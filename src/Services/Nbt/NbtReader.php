<?php

declare(strict_types=1);

namespace McToolkit\Services\Nbt;

/**
 * Minimal big-endian NBT walker tailored to `level.dat`, no external NBT library.
 *
 * Input is the raw bytes of `level.dat` as delivered by
 * {@see \McToolkit\Services\FileBridge::read()} — gzip-compressed compound in practice,
 * with a tolerant fallback for tool-generated files that were stored uncompressed.
 *
 * Every interesting value lives under the single root child compound `Data`:
 *
 *   Version.Name      -> "1.21.3"        (String)
 *   DataVersion       -> 4082            (Int)
 *   LevelName         -> "New World"     (String)
 *   GameType          -> 0               (Int; 0 survival / 1 creative / 2 adventure / 3 spectator)
 *   hardcore          -> 1b              (Byte/Boolean)
 *   LastPlayed        -> ms long since epoch
 *   WorldGenSettings.seed                (Long; legacy servers keep it as Data.RandomSeed Long)
 *   SpawnX / SpawnY / SpawnZ             (Int trio at Data level, up to 1.21.8)
 *   spawn.pos                            (IntArray [x, y, z], 1.21.9+)
 *
 * Walk model: the reader is SELECTIVE. {@see self::KEEP} describes the only branches it
 * materializes; every other tag (player inventories, dragon fight, game rules, custom
 * boss events, mod registries…) is skipped by arithmetic without building PHP values.
 * That keeps memory bounded on hostile input and means deep unrelated subtrees never
 * abort the parse. Skipping still walks nested lists/compounds, capped at
 * {@see self::MAX_SKIP_DEPTH}.
 *
 * Tag set covered (big-endian packed): End(0) Byte(1) Short(2) Int(3) Long(4) Float(5)
 * Double(6) ByteArray(7) String(8) List(9) Compound(10) IntArray(11) LongArray(12).
 *
 * Strings are Java "modified UTF-8" on disk; displayed strings are converted to real
 * UTF-8 ({@see self::modifiedUtf8ToUtf8()}) so they can travel through Livewire state.
 *
 * Error policy: malformed payloads raise {@see \DomainException} carrying ONE short
 * sentence of context ("level.dat could not be decoded: …") so pages can surface it in a
 * Notification body without leaking stack traces.
 */
final class NbtReader
{
    /** Nesting cap while SKIPPING unwanted subtrees (real level.dat files stay far below). */
    public const MAX_SKIP_DEPTH = 256;

    /** Refuse absurd decompressed sizes before gzdecode runs (gzip-bomb guard). */
    private const MAX_DECOMPRESSED_BYTES = 33_554_432;

    /** Int arrays longer than this are skipped instead of decoded (spawn.pos has 3). */
    private const MAX_DECODED_INT_ARRAY = 64;

    /**
     * Branches that are materialized. `true` = read this leaf (scalars, strings, int
     * arrays); an array = descend into this compound with the nested spec. Anything not
     * listed (and any list/compound sitting where a leaf is expected) is skipped.
     */
    private const KEEP = [
        'Data' => [
            'Version' => ['Name' => true],
            'DataVersion' => true,
            'LevelName' => true,
            'GameType' => true,
            'hardcore' => true,
            'LastPlayed' => true,
            'RandomSeed' => true,
            'WorldGenSettings' => ['seed' => true],
            'SpawnX' => true,
            'SpawnY' => true,
            'SpawnZ' => true,
            'spawn' => ['pos' => true, 'dimension' => true],
        ],
    ];

    /** Vanilla GameType ints; 3+ reserved values map upstream themselves. */
    public const GAME_TYPES = [
        0 => 'survival',
        1 => 'creative',
        2 => 'adventure',
        3 => 'spectator',
    ];

    /**
     * Parse `level.dat` bytes into the flat interest-key set consumed by WorldsService.
     *
     * @return array<string, mixed>|null null when the payload is empty or not an NBT stream
     *
     * @throws \DomainException on structurally broken data (friendly one-liner message).
     */
    public static function parseLevelDat(string $gzBytes): ?array
    {
        if ($gzBytes === '') {
            return null;
        }

        $cursor = new NbtCursor(self::inflate($gzBytes));

        if ($cursor->data === null || strlen($cursor->data) < 3) {
            return null;
        }

        // Root envelope: TAG_Compound + its name; vanilla writes a zero-length name but
        // third-party editors occasionally emit one — both accepted.
        if ($cursor->peekTagId() !== 10) {
            return null;
        }

        $cursor->advance();
        $cursor->skipString(); // root name, never displayed

        $tree = self::compoundBody($cursor, self::KEEP, 0);

        $data = is_array($tree['Data'] ?? null) ? $tree['Data'] : [];

        return self::interestKeys($data);
    }

    /**
     * Java writes NBT strings as "modified UTF-8": U+0000 as C0 80 and supplementary
     * characters (emoji…) as two 3-byte surrogate halves (CESU-8). PHP/JSON need real
     * UTF-8, so both forms are rewritten and anything still invalid is scrubbed.
     */
    public static function modifiedUtf8ToUtf8(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $value = str_replace("\xC0\x80", "\u{FFFD}", $raw);

        $value = preg_replace_callback(
            '/\xED([\xA0-\xAF])([\x80-\xBF])\xED([\xB0-\xBF])([\x80-\xBF])/',
            static function (array $m): string {
                $high = 0xD000 | ((ord($m[1]) & 0x3F) << 6) | (ord($m[2]) & 0x3F);
                $low = 0xD000 | ((ord($m[3]) & 0x3F) << 6) | (ord($m[4]) & 0x3F);
                $codePoint = 0x10000 + (($high - 0xD800) << 10) + ($low - 0xDC00);

                return mb_chr($codePoint, 'UTF-8') ?: "\u{FFFD}";
            },
            $value,
        ) ?? $value;

        return mb_scrub($value, 'UTF-8');
    }

    /* ------------------------------------------------------------------ */
    /*  Envelope                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Gzip-sniffing decoder: 0x1f 0x8b magic → bounded streaming inflate, else
     * assume already-raw NBT.
     */
    private static function inflate(string $bytes): ?string
    {
        if (strlen($bytes) >= 2 && $bytes[0] === "\x1F" && $bytes[1] === "\x8B") {
            $out = self::boundedInflate($bytes, ZLIB_ENCODING_GZIP, self::MAX_DECOMPRESSED_BYTES);

            if ($out === null || $out === '') {
                throw new \DomainException('level.dat could not be decoded: the gzip payload is damaged or decompressed metadata exceeds '.number_format(self::MAX_DECOMPRESSED_BYTES).' bytes.');
            }

            return $out;
        }

        return $bytes;
    }

    /**
     * Streaming inflate with an output-size ceiling enforced while input feeds in.
     *
     * @return string|null inflated bytes; null on corruption or ceiling breach
     */
    private static function boundedInflate(string $payload, int $encoding, int $maxBytes): ?string
    {
        try {
            $context = inflate_init($encoding);

            if ($context === false) {
                return null;
            }

            $out = '';

            foreach ((str_split($payload, 262144) ?: ['']) as $chunk) {
                if ($chunk === '') {
                    continue;
                }

                $part = @inflate_add($context, $chunk);

                if (! is_string($part)) {
                    return null;
                }

                $out .= $part;

                if (strlen($out) > $maxBytes) {
                    return null;
                }
            }

            $tail = @inflate_add($context, '', ZLIB_FINISH);

            if (! is_string($tail)) {
                return null;
            }

            $out .= $tail;

            return strlen($out) > $maxBytes || $out === '' ? null : $out;
        } catch (\Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Tree walkers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Named child set terminated by TAG_End(0); only children named in $spec are built.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    private static function compoundBody(NbtCursor $c, array $spec, int $depth): array
    {
        $out = [];

        while (true) {
            if ($c->atEnd()) {
                break; // graceful end-of-stream inside torn files
            }

            $tag = $c->peekTagId();
            $c->advance();

            if ($tag === 0) {
                break;
            }

            $name = $c->readString();
            $want = $spec[$name] ?? null;

            if (is_array($want) && $tag === 10) {
                $out[$name] = self::compoundBody($c, $want, $depth + 1);
            } elseif ($want === true && $tag !== 9 && $tag !== 10) {
                $out[$name] = self::leaf($tag, $c);
            } else {
                self::skip($tag, $c, $depth + 1);
            }
        }

        return $out;
    }

    /**
     * Reads one leaf payload (never a list or compound).
     *
     * @return int|float|string|list<int>|null
     */
    private static function leaf(int $tag, NbtCursor $c): int|float|string|array|null
    {
        return match ($tag) {
            1 => $c->readI8(),
            2 => $c->readI16(),
            3 => $c->readI32(),
            4 => $c->readI64BE(),
            5 => $c->readF32BE(),
            6 => $c->readF64BE(),
            8 => $c->readString(),
            11 => $c->readIntArray(self::MAX_DECODED_INT_ARRAY),
            7, 12 => self::skipThenNull($tag, $c),
            default => throw new \DomainException(sprintf('level.dat could not be decoded: unknown NBT tag id %d.', $tag)),
        };
    }

    private static function skipThenNull(int $tag, NbtCursor $c): mixed
    {
        self::skip($tag, $c, 0);

        return null;
    }

    /** Advances over one payload of $tag without materializing anything. */
    private static function skip(int $tag, NbtCursor $c, int $depth): void
    {
        if ($depth > self::MAX_SKIP_DEPTH) {
            throw new \DomainException('level.dat could not be decoded: nesting deeper than '.self::MAX_SKIP_DEPTH.' levels.');
        }

        switch ($tag) {
            case 1:
                $c->skip(1);

                return;
            case 2:
                $c->skip(2);

                return;
            case 3:
            case 5:
                $c->skip(4);

                return;
            case 4:
            case 6:
                $c->skip(8);

                return;
            case 7:
                $c->skip($c->readCount());

                return;
            case 8:
                $c->skipString();

                return;
            case 11:
                $c->skip($c->readCount() * 4);

                return;
            case 12:
                $c->skip($c->readCount() * 8);

                return;
            case 9:
                $itemTag = ord($c->readByte());
                $count = $c->readCount();

                if ($count === 0 || $itemTag === 0) {
                    return;
                }

                $fixed = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8][$itemTag] ?? null;

                if ($fixed !== null) {
                    $c->skip($count * $fixed);

                    return;
                }

                for ($i = 0; $i < $count; $i++) {
                    if ($c->atEnd()) {
                        throw new \DomainException('level.dat could not be decoded: unexpected end of data.');
                    }

                    self::skip($itemTag, $c, $depth + 1);
                }

                return;
            case 10:
                while (true) {
                    if ($c->atEnd()) {
                        throw new \DomainException('level.dat could not be decoded: unexpected end of data.');
                    }

                    $inner = $c->peekTagId();
                    $c->advance();

                    if ($inner === 0) {
                        return;
                    }

                    $c->skipString();
                    self::skip($inner, $c, $depth + 1);
                }
                // no break — the loop only exits through return
            default:
                throw new \DomainException(sprintf('level.dat could not be decoded: unknown NBT tag id %d.', $tag));
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Interest-key extraction                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Flatten the whitelisted subset of `Data.*` keys for callers.
     *
     * @param  array<mixed>  $data
     * @return array{version:?string,data_version:?int,level_name:?string,game_type:?int,
     *               hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}
     */
    private static function interestKeys(array $data): array
    {
        $versionNode = is_array($data['Version'] ?? null) ? $data['Version'] : [];
        $genSettings = is_array($data['WorldGenSettings'] ?? null) ? $data['WorldGenSettings'] : [];
        $seed = self::optionalInt($genSettings['seed'] ?? null) ?? self::optionalInt($data['RandomSeed'] ?? null);

        // 1.21.9+ replaced SpawnX/Y/Z with `spawn: {dimension, pos: [I; x, y, z], …}`.
        $spawnPos = is_array($data['spawn'] ?? null) && is_array($data['spawn']['pos'] ?? null)
            ? array_values($data['spawn']['pos'])
            : [];

        $versionName = is_string($versionNode['Name'] ?? null) ? self::modifiedUtf8ToUtf8($versionNode['Name']) : '';
        $levelName = is_string($data['LevelName'] ?? null) ? self::modifiedUtf8ToUtf8($data['LevelName']) : '';

        return [
            'version' => $versionName !== '' ? $versionName : null,
            'data_version' => self::optionalInt($data['DataVersion'] ?? null),
            'level_name' => $levelName !== '' ? $levelName : null,
            'game_type' => self::optionalInt($data['GameType'] ?? null),
            'hardcore' => (bool) ($data['hardcore'] ?? false),
            'last_played' => self::lastPlayedIso($data['LastPlayed'] ?? null),
            // Always decimal text: JSON-safe across int boundaries and friendlier in UI.
            'seed' => $seed !== null ? (string) $seed : null,
            'spawn_x' => self::optionalInt($data['SpawnX'] ?? null) ?? self::optionalInt($spawnPos[0] ?? null),
            'spawn_y' => self::optionalInt($data['SpawnY'] ?? null) ?? self::optionalInt($spawnPos[1] ?? null),
            'spawn_z' => self::optionalInt($data['SpawnZ'] ?? null) ?? self::optionalInt($spawnPos[2] ?? null),
        ];
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /** Millis-since-epoch long → ISO-8601 UTC string; nonsense ranges collapse to null. */
    private static function lastPlayedIso(mixed $ms): ?string
    {
        if (! is_int($ms)) {
            return null;
        }

        // Plausibility window: after 2000-01-01, before +100y from now.
        $minMs = 946_684_800_000;
        $maxMs = (time() + 100 * 365 * 86_400) * 1000;

        if ($ms < $minMs || $ms > $maxMs) {
            return null;
        }

        return gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
    }
}

/**
 * Offset-tracking binary reader backing {@see NbtReader}.
 *
 * Kept in this same file on purpose: it is a private implementation detail of the
 * walker and the build-contract file map reserves `src/Services/Nbt/` for
 * NbtReader.php alone.
 *
 * @internal
 */
final class NbtCursor
{
    /** Guard against cursor runaway when caller-provided lengths exceed payload size. */
    private const MAX_READ_BLOCK = 16_777_216;

    public ?string $data;

    public int $offset = 0;

    private int $length;

    public function __construct(?string $data)
    {
        $this->data = $data;
        $this->length = $data === null ? 0 : strlen($data);
    }

    public function atEnd(): bool
    {
        return $this->offset >= $this->length;
    }

    /**
     * Peek the upcoming tag id without consuming it; end-of-stream reads as 0 so
     * compound termination stays branch-free upstream.
     */
    public function peekTagId(): int
    {
        if ($this->data === null || $this->offset >= $this->length) {
            return 0;
        }

        return ord($this->data[$this->offset]);
    }

    public function advance(): void
    {
        $this->offset++;
    }

    public function readByte(): string
    {
        return $this->take(1);
    }

    public function readI8(): int
    {
        $raw = ord($this->take(1));

        return $raw > 127 ? $raw - 256 : $raw;
    }

    public function readI16(): int
    {
        $raw = $this->readU16();

        return $raw >= 0x8000 ? $raw - 65_536 : $raw;
    }

    public function readI32(): int
    {
        $raw = (int) (unpack('N', $this->take(4))[1] ?? 0);

        return $raw >= 0x80000000 ? $raw - 4_294_967_296 : $raw;
    }

    public function readU16(): int
    {
        return (int) (unpack('n', $this->take(2))[1] ?? 0);
    }

    /** Native signed big-endian long: unpack('J') reinterprets two's complement onto PHP's int64. */
    public function readI64BE(): int
    {
        return (int) (unpack('J', $this->take(8))[1] ?? 0);
    }

    public function readF32BE(): float
    {
        return (float) (unpack('G', $this->take(4))[1] ?? 0.0);
    }

    public function readF64BE(): float
    {
        return (float) (unpack('E', $this->take(8))[1] ?? 0.0);
    }

    /** u16 length-prefixed (modified UTF-8) string, raw bytes. */
    public function readString(): string
    {
        return $this->take($this->readU16());
    }

    public function skipString(): void
    {
        $this->skip($this->readU16());
    }

    /**
     * Signed i32 element count of arrays/lists; negative counts are corrupt data.
     */
    public function readCount(): int
    {
        $count = $this->readI32();

        if ($count < 0) {
            throw new \DomainException('level.dat could not be decoded: negative element count.');
        }

        return $count;
    }

    /**
     * IntArray payload; arrays longer than $max are skipped and read as null.
     *
     * @return list<int>|null
     */
    public function readIntArray(int $max): ?array
    {
        $count = $this->readCount();

        if ($count > $max) {
            $this->skip($count * 4);

            return null;
        }

        $values = [];

        for ($i = 0; $i < $count; $i++) {
            $values[] = $this->readI32();
        }

        return $values;
    }

    /** Advance without copying; bounds-checked like take(). */
    public function skip(int $bytes): void
    {
        $this->guard($bytes);
        $this->offset += $bytes;
    }

    private function take(int $bytes): string
    {
        $this->guard($bytes);

        $chunk = substr((string) $this->data, $this->offset, $bytes);
        $this->offset += $bytes;

        return $chunk;
    }

    private function guard(int $bytes): void
    {
        if ($bytes < 0 || $bytes > self::MAX_READ_BLOCK * 2) {
            throw new \DomainException('level.dat could not be decoded: declared block of '.number_format($bytes).' bytes exceeds supported size.');
        }

        if ($this->data === null || $this->offset + $bytes > $this->length) {
            throw new \DomainException('level.dat could not be decoded: unexpected end of data.');
        }
    }
}
