<?php

declare(strict_types=1);

namespace McToolkit\Services\Properties;

use App\Exceptions\Repository\FileExistsException;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use McToolkit\Services\FileBridge;

/**
 * Parses and rewrites the Minecraft `server.properties` file while preserving
 * everything it does not manage (comments, blank lines, key ordering, panel-owned keys).
 *
 * Parsing follows java.util.Properties::load (what the server itself uses):
 * - Line endings CRLF / CR / LF are all accepted; a rewrite normalizes to LF and ends
 *   with exactly one trailing LF (vanilla accepts both).
 * - `#` and `!` start comment lines (never continued); blank lines are kept verbatim.
 * - A line ending in an odd number of backslashes continues on the next line (the next
 *   line's leading whitespace is dropped); such a logical line is kept verbatim unless
 *   its key is rewritten.
 * - The key ends at the first unescaped `=`, `:` or whitespace; whitespace around the
 *   separator is skipped; the value keeps trailing whitespace.
 * - Escapes are decoded in ONE pass: `\t` `\n` `\r` `\f`, `\uXXXX` (surrogate pairs are
 *   combined, lone surrogates become U+FFFD), any other `\x` → `x`. A literal backslash
 *   followed by "u0041" therefore survives a round trip.
 * - The file is assumed to be UTF-8 (modern servers read it that way); `\uXXXX` escapes
 *   written by older tools are decoded, rewritten values are emitted as literal UTF-8.
 *
 * Writing escapes the way java.util.Properties::store does (`\\`, `\=`, `\:`, `\#`, `\!`,
 * control characters, a leading space of a value, every space of a key), so the server
 * reads back exactly the string that was written. Untouched logical lines are copied
 * byte for byte; when a rewritten key appears several times only one line remains.
 *
 * Error semantics (mapped onto UI notifications by the calling page):
 * - {@see self::read()} returns `exists => false` for a missing file and lets
 *   {@see ConnectionException} (and other daemon errors) bubble.
 * - {@see self::write()} re-parses its own candidate output and compares it against the
 *   merged expectation BEFORE asking wings to persist anything, failing with
 *   \LogicException when serialization drifts.
 * - An unreadable / oversized existing file or a failed write surfaces as
 *   \RuntimeException (previous chained); node outages as {@see ConnectionException}.
 */
final class PropertiesParser
{
    /**
     * Keys owned by the panel allocation (they are disabled inputs labeled
     * "Set by the panel" on the properties page). The parser never replaces their
     * lines, whatever the caller passes in.
     */
    public const MANAGED_KEYS = ['server-port', 'server-ip', 'query.port'];

    private const FILE_PATH = 'server.properties';

    /** Ceiling for one read pass; real files stay under ~5 KiB. */
    private const MAX_READ_BYTES = 262144;

    /** Keys this parser agrees to write (every vanilla / Paper key fits). */
    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9._-]{0,127}$/';

    public function __construct(private readonly FileBridge $bridge) {}

    /**
     * @return array{
     *     exists: bool,
     *     values: array<string,string>,
     *     comments_preserved: list<string>,
     * }
     * `values` maps decoded key => decoded value; `comments_preserved` lists comment
     * bodies (marker characters stripped, original encounter order) purely for parity
     * display.
     *
     * @throws ConnectionException
     */
    public function read(Server $server): array
    {
        $raw = $this->bridge->read($server, self::FILE_PATH, self::MAX_READ_BYTES);

        if ($raw === null) {
            return [
                'exists' => false,
                'values' => [],
                'comments_preserved' => [],
            ];
        }

        [$values, $comments] = $this->parse(self::normalizeLineEndings($raw));

        return [
            'exists' => true,
            'values' => $values,
            'comments_preserved' => $comments,
        ];
    }

    /**
     * Write $values back preserving unknown lines/comments/order. Managed keys
     * ({@see self::MANAGED_KEYS}) present in $values are silently dropped so the
     * allocation-owned settings survive untouched.
     *
     * @param  array<string,mixed>  $values  scalars coerced through strval(); booleans
     *                                          become the canonical lowercase `true`/`false`.
     *
     * @throws ConnectionException when the node cannot be reached.
     * @throws \RuntimeException when the file cannot be read back or proves read-only.
     * @throws \LogicException when a key is unusable or the internal round-trip
     *                         validation fails (nothing was written).
     */
    public function write(Server $server, array $values): void
    {
        foreach (self::MANAGED_KEYS as $managed) {
            unset($values[$managed]);
        }

        if ($values === []) {
            return;
        }

        $normalized = [];

        // Canonical scalar coercion first: bool -> true/false, everything else via strval().
        foreach ($values as $key => $value) {
            $key = (string) $key;

            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new \InvalidArgumentException("Refusing to write the server.properties key [{$key}].");
            }

            if ($value !== null && !is_scalar($value)) {
                throw new \InvalidArgumentException("The value of [{$key}] must be a scalar.");
            }

            $normalized[$key] = is_bool($value)
                ? ($value ? 'true' : 'false')
                : strval($value ?? '');
        }

        try {
            $raw = $this->bridge->read($server, self::FILE_PATH, self::MAX_READ_BYTES);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'The server.properties file could not be read before saving.',
                0,
                $e,
            );
        }

        // read() also answers null for a file that exists but exceeds the read cap —
        // rebuilding from nothing would then wipe every other setting.
        if ($raw === null && $this->bridge->exists($server, self::FILE_PATH)) {
            throw new \RuntimeException('The server.properties file is too large to be edited safely.');
        }

        $base = $raw === null ? '' : self::normalizeLineEndings($raw);
        $candidate = $this->build($base, $normalized);

        // Round-trip proof BEFORE touching the daemon: re-parse our own candidate and
        // require it to yield exactly base-values + requested overrides. (Plain key
        // assignment instead of array_merge(): a stray numeric key in the file must not
        // be renumbered.)
        $expected = $this->parse($base)[0];

        foreach ($normalized as $key => $value) {
            $expected[$key] = $value;
        }

        if ($this->parse($candidate)[0] !== $expected) {
            throw new \LogicException('server.properties round-trip validation failed before writing.');
        }

        try {
            $this->bridge->write($server, self::FILE_PATH, $candidate);
        } catch (ConnectionException|FileExistsException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'The properties file appears to be read-only on this node.',
                0,
                $e,
            );
        }
    }

    /**
     * @return array{0: array<string,string>, 1: list<string>}
     */
    private function parse(string $normalized): array
    {
        $values = [];
        $comments = [];

        foreach (self::logicalLines($normalized) as $line) {
            if ($line['kind'] === 'comment') {
                // Body after the single-byte marker, trimmed — order kept for parity UI.
                $comments[] = trim(substr(ltrim($line['lines'][0], " \t\f"), 1));

                continue;
            }

            if ($line['kind'] === 'pair') {
                $values[$line['key']] = $line['value'];
            }
        }

        return [$values, $comments];
    }

    /**
     * Rebuild the whole document: comment/blank/untouched lines verbatim, rewritten keys
     * in place of their first occurrence (later duplicates dropped), missing requested
     * keys appended at the end, exactly one trailing LF.
     *
     * @param  array<string,string>  $values
     */
    private function build(string $base, array $values): string
    {
        $out = [];
        $pending = $values;
        $written = [];

        foreach (self::logicalLines($base) as $line) {
            if ($line['kind'] === 'pair' && array_key_exists($line['key'], $values)) {
                $key = $line['key'];

                if (!isset($written[$key])) {
                    $out[] = self::escape($key, true) . '=' . self::escape($values[$key], false);
                    $written[$key] = true;
                    unset($pending[$key]);
                }

                continue;
            }

            array_push($out, ...$line['lines']);
        }

        // Trailing blank lines (the empty tail after the final LF included) would
        // otherwise grow by one on every save.
        while ($out !== [] && trim((string) end($out), " \t\f") === '') {
            array_pop($out);
        }

        foreach ($pending as $key => $value) {
            $out[] = self::escape((string) $key, true) . '=' . self::escape($value, false);
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * Split a normalized (LF-only) document into java.util.Properties logical lines.
     *
     * @return list<array{kind: 'blank'|'comment'|'pair', lines: list<string>, key: string, value: string}>
     */
    private static function logicalLines(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $physical = explode("\n", $normalized);
        $count = count($physical);
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $first = $physical[$i];
            $content = ltrim($first, " \t\f");

            if ($content === '') {
                $result[] = ['kind' => 'blank', 'lines' => [$first], 'key' => '', 'value' => ''];

                continue;
            }

            if ($content[0] === '#' || $content[0] === '!') {
                $result[] = ['kind' => 'comment', 'lines' => [$first], 'key' => '', 'value' => ''];

                continue;
            }

            $lines = [$first];

            // Odd number of trailing backslashes = continuation onto the next line.
            while (self::endsWithContinuation($content) && $i + 1 < $count) {
                $i++;
                $lines[] = $physical[$i];
                $content = substr($content, 0, -1) . ltrim($physical[$i], " \t\f");
            }

            if (self::endsWithContinuation($content)) {
                // Continuation at end of file: Java simply drops the dangling backslash.
                $content = substr($content, 0, -1);
            }

            [$key, $value] = self::splitPair($content);

            $result[] = ['kind' => 'pair', 'lines' => $lines, 'key' => $key, 'value' => $value];
        }

        return $result;
    }

    private static function endsWithContinuation(string $content): bool
    {
        $backslashes = strlen($content) - strlen(rtrim($content, '\\'));

        return $backslashes % 2 === 1;
    }

    /**
     * java.util.Properties key/value split: the key ends at the first unescaped `=`, `:`
     * or whitespace; whitespace and at most one `=`/`:` after it are skipped.
     *
     * @return array{0: string, 1: string} decoded key and value
     */
    private static function splitPair(string $content): array
    {
        $length = strlen($content);
        $keyLength = 0;
        $valueStart = $length;
        $hasSeparator = false;
        $precedingBackslash = false;

        while ($keyLength < $length) {
            $char = $content[$keyLength];

            if (($char === '=' || $char === ':') && !$precedingBackslash) {
                $valueStart = $keyLength + 1;
                $hasSeparator = true;

                break;
            }

            if (($char === ' ' || $char === "\t" || $char === "\f") && !$precedingBackslash) {
                $valueStart = $keyLength + 1;

                break;
            }

            $precedingBackslash = $char === '\\' ? !$precedingBackslash : false;
            $keyLength++;
        }

        while ($valueStart < $length) {
            $char = $content[$valueStart];

            if ($char !== ' ' && $char !== "\t" && $char !== "\f") {
                if (!$hasSeparator && ($char === '=' || $char === ':')) {
                    $hasSeparator = true;
                } else {
                    break;
                }
            }

            $valueStart++;
        }

        return [
            self::unescape(substr($content, 0, $keyLength)),
            self::unescape(substr($content, $valueStart)),
        ];
    }

    /**
     * Single-pass java.util.Properties unescaper (see class docblock). Bytes outside
     * escapes are copied untouched, so UTF-8 text passes through intact.
     */
    private static function unescape(string $input): string
    {
        if (!str_contains($input, '\\')) {
            return $input;
        }

        $out = '';
        $units = [];
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];

            if ($char !== '\\' || $index + 1 >= $length) {
                // A trailing lone backslash is dropped, like Java does.
                if ($char !== '\\') {
                    $out .= self::flushUnits($units) . $char;
                }

                continue;
            }

            $next = $input[++$index];

            if ($next === 'u' && preg_match('/\G[0-9a-fA-F]{4}/', $input, $hex, 0, $index + 1) === 1) {
                $units[] = (int) hexdec($hex[0]);
                $index += 4;

                continue;
            }

            $out .= self::flushUnits($units) . match ($next) {
                't' => "\t",
                'n' => "\n",
                'r' => "\r",
                'f' => "\f",
                default => $next,
            };
        }

        return $out . self::flushUnits($units);
    }

    /**
     * Turn buffered UTF-16 code units from `\uXXXX` escapes into UTF-8, pairing
     * surrogates; empties the buffer.
     *
     * @param  list<int>  $units
     */
    private static function flushUnits(array &$units): string
    {
        $out = '';
        $count = count($units);

        for ($i = 0; $i < $count; $i++) {
            $unit = $units[$i];

            if ($unit >= 0xD800 && $unit <= 0xDBFF && $i + 1 < $count
                && $units[$i + 1] >= 0xDC00 && $units[$i + 1] <= 0xDFFF) {
                $unit = 0x10000 + (($unit - 0xD800) << 10) + ($units[$i + 1] - 0xDC00);
                $i++;
            } elseif ($unit >= 0xD800 && $unit <= 0xDFFF) {
                $unit = 0xFFFD;
            }

            $char = mb_chr($unit, 'UTF-8');
            $out .= $char === false ? "\u{FFFD}" : $char;
        }

        $units = [];

        return $out;
    }

    /**
     * Inverse of {@see self::unescape()}, mirroring java.util.Properties::store: keys
     * escape every space, values only a leading one (Java would strip it otherwise).
     */
    private static function escape(string $input, bool $isKey): string
    {
        $out = '';
        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $char = $input[$index];

            $out .= match ($char) {
                '\\' => '\\\\',
                "\t" => '\\t',
                "\n" => '\\n',
                "\r" => '\\r',
                "\f" => '\\f',
                '=', ':', '#', '!' => '\\' . $char,
                ' ' => ($isKey || $index === 0) ? '\\ ' : ' ',
                default => (ord($char) < 0x20 || ord($char) === 0x7F)
                    ? sprintf('\\u%04X', ord($char))
                    : $char,
            };
        }

        return $out;
    }

    private static function normalizeLineEndings(string $raw): string
    {
        // A UTF-8 BOM would otherwise glue itself to the first key.
        if (str_starts_with($raw, "\xEF\xBB\xBF")) {
            $raw = substr($raw, 3);
        }

        return str_replace(["\r\n", "\r"], "\n", $raw);
    }
}
