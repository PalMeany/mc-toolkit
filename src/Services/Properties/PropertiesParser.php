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
 * Parsing notes:
 * - The wire endpoint answers with the raw UTF-8 file body. Legacy UTF-16LE exports
 *   written by ancient tools are NOT supported (assume utf8 — deliberate v1 scope,
 *   mirrors how every modern egg ships the file).
 * - Line endings are tolerated as CRLF/LF; every serialized rewrite normalizes to LF
 *   (vanilla accepts both).
 * - Key/value escaping follows java.util.Properties semantics actually produced by
 *   the vanilla serializer: `\:` `\=` `\n` `\r` `\t` `\\`. Decode and encode are
 *   symmetric single-pass transforms so a round trip of untouched content yields
 *   byte-identical output.
 *
 * Error semantics (mapped onto UI notifications by the calling page):
 * - {@see self::read()} returns `exists => false` for a missing/unreadable file and
 *   lets {@see ConnectionException} bubble for an unreachable node.
 * - {@see self::write()} runs a FULL-STRING round-trip validation before asking wings
 *   to persist anything: it re-parses its own candidate output and compares it against
 *   the merged expectation, failing with \LogicException when serialization drifts.
 * - Read-only filesystems surface as \RuntimeException (friendly, previous chained);
 *   node outages surface as {@see ConnectionException}.
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

    public function __construct(private readonly FileBridge $bridge) {}

    /**
     * @return array{
     *     exists: bool,
     *     values: array<string,string>,
     *     comments_preserved: list<string>,
     * }
     * `values` maps decoded key => decoded value; `comments_preserved` lists comment
     * bodies (marker characters stripped, original encounter order) purely for parity
     * display; unknown/malformed lines are ignored here yet preserved verbatim on write.
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
     * @throws \LogicException when the internal round-trip validation fails (nothing was written).
     */
    public function write(Server $server, array $values): void
    {
        foreach (self::MANAGED_KEYS as $managed) {
            unset($values[$managed]);
        }

        if ($values === []) {
            return;
        }

        // Canonical scalar coercion first: bool -> true/false, everything else via strval().
        foreach ($values as $key => $value) {
            $values[$key] = is_bool($value)
                ? ($value ? 'true' : 'false')
                : strval($value);
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

        $base = $raw === null ? '' : self::normalizeLineEndings($raw);
        $candidate = $this->build($base, $values);

        // Round-trip proof BEFORE touching the daemon: re-parse our own candidate and
        // require it to yield exactly base-values + requested overrides.
        $expected = array_merge($base === '' ? [] : $this->parse($base)[0], $values);

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

        foreach (explode("\n", $normalized) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '') {
                continue;
            }

            if ($trimmed[0] === '#' || $trimmed[0] === '!') {
                // Preserve the body after the marker, trimmed — order kept for parity UI.
                $comments[] = trim(substr($trimmed, 1)); // marker is a single ASCII byte; body stays raw UTF-8

                continue;
            }

            $pair = self::splitPair($line);

            if ($pair === null) {
                continue; // malformed/unrecognized: never surfaced, still preserved verbatim on write
            }

            [$key, $value] = $pair;

            $values[$key] = rtrim($value);
        }

        return [$values, $comments];
    }

    /**
     * Rebuild the whole document: managed/unknown/comment lines verbatim, known keys
     * positionally replaced, missing requested keys appended at the end, trailing LF.
     *
     * @param  array<string,string>  $values
     */
    private function build(string $base, array $values): string
    {
        $lines = [];
        $pending = $values;

        foreach (explode("\n", $base) as $line) {
            $trimmed = ltrim($line);

            if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === '!') {
                $lines[] = $line;

                continue;
            }

            $pair = self::splitPair($line);

            if ($pair === null) {
                $lines[] = $line;

                continue;
            }

            $key = $pair[0];

            if (array_key_exists($key, $pending)) {
                $lines[] = self::escape($key) . '=' . self::escape($pending[$key]);
                unset($pending[$key]);

                continue;
            }

            $lines[] = $line;
        }

        foreach ($pending as $key => $value) {
            $lines[] = self::escape($key) . '=' . self::escape($value);
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * Byte-level scanner locating the FIRST UNESCAPED `=`; java.util.Properties treats
     * the first such separator as key/value boundary, later unescaped separators belong
     * to the value.
     *
     * @return array{0:string,1:string}|null null when the line carries no separator.
     */
    private static function splitPair(string $line): ?array
    {
        $length = strlen($line);

        for ($index = 0; $index < $length; $index++) {
            $char = $line[$index];

            if ($char === '\\') {
                $index++; // skip the escaped byte entirely

                continue;
            }

            if ($char === '=') {
                return [
                    self::unescape(substr($line, 0, $index)),
                    self::unescape(substr($line, $index + 1)),
                ];
            }
        }

        return null;
    }

    /**
     * Single-pass java.util.Properties unescaper: `\\` `\:` `\=` `\n` `\r` `\t`;
     * any other escaped byte degrades to that byte (documented upstream behavior).
     *
     * A preceding pass decodes legacy `\uXXXX` escapes first — java.util.Properties
     * (and vanilla's own serializer for e.g. MOTD section signs §) writes them as
     * ASCII backslash sequences; without this they surface as literal text and the
     * MOTD palette/toolbar never fires. Round-trip stays symmetric because both
     * sides decode through the same parse() (escape() keeps emitting literal UTF-8,
     * which the game's UTF-8 reader accepts).
     */
    private static function unescape(string $input): string
    {
        $decoded = preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            static fn (array $match): string => mb_chr(hexdec($match[1]), 'UTF-8'),
            $input,
        );
        if (is_string($decoded)) {
            $input = $decoded;
        }

        return preg_replace_callback(
            '/\\\\(.)/s',
            static fn (array $match): string => match ($match[1]) {
                '\\' => '\\',
                ':' => ':',
                '=' => '=',
                'n' => "\n",
                'r' => "\r",
                't' => "\t",
                default => $match[1],
            },
            $input,
        ) ?? $input;
    }

    /** Inverse of {@see self::unescape()} — byte-order deterministic. */
    private static function escape(string $input): string
    {
        $out = '';

        $length = strlen($input);

        for ($index = 0; $index < $length; $index++) {
            $out .= match ($input[$index]) {
                '\\' => '\\\\',
                ':' => '\\:',
                '=' => '\\=',
                "\n" => '\\n',
                "\r" => '\\r',
                "\t" => '\\t',
                default => $input[$index],
            };
        }

        return $out;
    }

    private static function normalizeLineEndings(string $raw): string
    {
        return str_replace("\r\n", "\n", $raw);
    }
}
