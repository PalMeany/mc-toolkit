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
 *   SpawnX / SpawnY / SpawnZ             (Int trio at Data level)
 *
 * Tag set covered (big-endian packed): End(0) Byte(1) Short(2) Int(3) Long(4) Float(5)
 * Double(6) ByteArray(7) String(8) List(9) Compound(10) IntArray(11) LongArray(12).
 * Walk depth is capped; exceeding it aborts gracefully instead of recursing forever.
 *
 * Error policy: malformed payloads raise {@see \DomainException} carrying ONE short
 * sentence of context ("level.dat could not be decoded: …") so pages can surface it in a
 * Notification body without leaking stack traces.
 */
final class NbtReader
{
    /** Hard recursion cap inside nested compounds/lists. */
    public const MAX_DEPTH = 8;

    /** Refuse absurd decompressed sizes before gzdecode runs (gzip-bomb guard). */
    private const MAX_DECOMPRESSED_BYTES = 33_554_432;

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
        if ((int) ord($cursor->peekByte()) !== 10) {
            return null;
        }

        $cursor->advance();

        $rootName = $cursor->readString();
        unset($rootName); // never displayed, kept for spec clarity

        $tree = self::compoundBody($cursor);

        $data = is_array($tree['Data'] ?? null) ? $tree['Data'] : [];

        return self::interestKeys($data);
    }

    /* ------------------------------------------------------------------ */
    /*  Envelope                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Gzip-sniffing decoder: 0x1f 0x8b magic → bounded streaming inflate, else
     * assume already-raw NBT.
     *
     * The ceiling is enforced WHILE feeding chunks, so the historic post-hoc
     * "check after gzdecode already allocated" ordering is gone — the guard below
     * (MAX_DECOMPRESSED_BYTES) is now true to its own comment.
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
    /*  Big-endian primitive readers                                       */
    /* ------------------------------------------------------------------ */

    private static function i8(NbtCursor $c): int
    {
        $raw = ord($c->readByte());

        return $raw > 127 ? $raw - 256 : $raw;
    }

    /** Signed big-endian short: u16 then two's-complement fold. */
    private static function i16(NbtCursor $c): int
    {
        $raw = $c->readU16();

        return $raw >= 0x8000 ? $raw - 65_536 : $raw;
    }

    private static function i32(NbtCursor $c): int
    {
        $raw = $c->readU32();

        return $raw >= 0x80000000 ? $raw - 4_294_967_296 : $raw;
    }

    /**
     * Signed 64-bit long. PHP's unpack('J') reinterprets the two's-complement
     * big-endian bytes straight onto a native int — negative seeds come back correct
     * (verified probe: pack/unpack round-trip of -4172144997902289642).
     */
    private static function i64(NbtCursor $c): int
    {
        return $c->readI64BE();
    }

    private static function f32(NbtCursor $c): float
    {
        return $c->readF32BE();
    }

    private static function f64(NbtCursor $c): float
    {
        return $c->readF64BE();
    }

    private static function string(NbtCursor $c): string
    {
        return $c->readString();
    }

    /* ------------------------------------------------------------------ */
    /*  Tree walkers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Parse a named payload after its tag id byte was consumed by the caller.
     *
     * @return mixed scalar/array value; ByteArray/IntArray/LongArray contents are skipped.
     */
    private static function payload(int $tag, NbtCursor $c, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \DomainException('level.dat could not be decoded: nesting deeper than '.self::MAX_DEPTH.' levels is not supported.');
        }

        return match ($tag) {
            1 => self::i8($c),
            2 => self::i16($c),
            3 => self::i32($c),
            4 => self::i64($c),
            5 => self::f32($c),
            6 => self::f64($c),
            7 => $c->skipPrefixedBytes(),             // ByteArray → advance over contents
            8 => self::string($c),
            9 => self::listPayload($c, $depth),       // List
            10 => self::compoundBody($c, $depth + 1), // Compound
            11, 12 => $c->skipNumericArray(),         // IntArray / LongArray → advance over contents
            default => throw new \DomainException(sprintf('level.dat could not be decoded: unknown NBT tag id %d.', $tag)),
        };
    }

    /** Named child set terminated by TAG_End(0). */
    private static function compoundBody(NbtCursor $c, int $depth = 0): array
    {
        $out = [];

        while (true) {
            // peekTagId() is non-consuming; every exit path below must advance.
            if ($c->offset >= strlen((string) $c->data)) {
                break; // graceful end-of-stream inside torn files
            }

            $tag = $c->peekTagId();

            if ($tag === 0) {
                $c->advance();

                break;
            }

            $c->advance(); // consume the tag id byte before reading [len][name]

            $name = $c->readString();
            $out[$name] = self::payload($tag, $c, $depth);
        }

        return $out;
    }

    private static function listPayload(NbtCursor $c, int $depth): array
    {
        [$itemTag, $count] = $c->readListHeader();

        if ($itemTag === 0 || $count <= 0) {
            return [];
        }

        $items = [];

        for ($i = 0; $i < $count; $i++) {
            $items[] = self::payload($itemTag, $c, $depth + 1);
        }

        return $items;
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
        $seedNode = $data['WorldGenSettings']['seed']
            ?? $data['RandomSeed']
            ?? null;

        $seed = self::optionalLong($seedNode);

        return [
            'version' => is_string($versionNode['Name'] ?? null) ? strval($versionNode['Name']) : null,
            'data_version' => self::optionalInt($data['DataVersion'] ?? null),
            'level_name' => isset($data['LevelName']) && is_string($data['LevelName']) && $data['LevelName'] !== ''
                ? strval($data['LevelName'])
                : null,
            'game_type' => self::optionalInt($data['GameType'] ?? null),
            'hardcore' => (bool) ($data['hardcore'] ?? false),
            'last_played' => self::lastPlayedIso($data['LastPlayed'] ?? null),
            // Always decimal text: JSON-safe across int boundaries and friendlier in UI.
            'seed' => $seed !== null ? (string) $seed : null,
            'spawn_x' => self::optionalInt($data['SpawnX'] ?? null),
            'spawn_y' => self::optionalInt($data['SpawnY'] ?? null),
            'spawn_z' => self::optionalInt($data['SpawnZ'] ?? null),
        ];
    }

    private static function optionalInt(mixed $value): ?int
    {
        return is_int($value) ? $value : null;
    }

    /**
     * Accepts int-typed numerics only (long/int tags land as PHP ints after unpack);
     * nested dimension compounds or stringified seeds from third-party editors stay out.
     */
    private static function optionalLong(mixed $value): ?int
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

        try {
            return gmdate('Y-m-d\TH:i:s\Z', intdiv($ms, 1000));
        } catch (\Throwable) {
            return null;
        }
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

    public function __construct(?string $data)
    {
        $this->data = $data;
    }

    /** Peek the next byte without consuming it; null at end-of-stream. */
    public function peekByte(): ?string
    {
        if ($this->data === null || $this->offset >= strlen($this->data)) {
            throw new \DomainException('level.dat could not be decoded: unexpected end of data.');
        }

        return $this->data[$this->offset];
    }

    /**
     * Peek the upcoming tag id without consuming it; TAG_End(0) and end-of-stream
     * both read as 0 so compound termination stays branch-free upstream.
     */
    public function peekTagId(): int
    {
        if ($this->data === null || $this->offset >= strlen($this->data)) {
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
        $byte = $this->peekByte();
        $this->offset++;

        return strval($byte);
    }

    public function readU16(): int
    {
        $unpacked = unpack('n', $this->take(2));
        $value = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;

        return (int) $value;
    }

    public function readU32(): int
    {
        $unpacked = unpack('N', $this->take(4));
        $value = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;

        return (int) $value;
    }

    /** Native signed big-endian long via two's-complement reinterpretation. */
    public function readI64BE(): int
    {
        $unpacked = unpack('J', $this->take(8));
        $value = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;

        return (int) $value;
    }

    public function readF32BE(): float
    {
        $unpacked = unpack('G', $this->take(4));
        $value = is_array($unpacked) ? ($unpacked[1] ?? 0.0) : 0.0;

        return (float) $value;
    }

    public function readF64BE(): float
    {
        $unpacked = unpack('E', $this->take(8));
        $value = is_array($unpacked) ? ($unpacked[1] ?? 0.0) : 0.0;

        return (float) $value;
    }

    /** u16 length-prefixed UTF-8 string. */
    public function readString(): string
    {
        return $this->take($this->readU16());
    }

    /** [itemTagId, itemCount] prefix pair of an NBT list. */
    public function readListHeader(): array
    {
        $itemTag = ord($this->readByte());

        $unpacked = unpack('N', $this->take(4));
        $count = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;

        return [$itemTag, (int) $count];
    }

    /** Skip a length-prefixed raw byte array (ByteArray tag contents). */
    public function skipPrefixedBytes(): bool
    {
        $unpacked = unpack("N", $this->take(4));

        $length = $unpacked[1] ?? 0;
        $this->take((int) $length);

        return true;
    }

    /** Skip IntArray or LongArray payload by arithmetic advance (8 bytes per element). */
    public function skipNumericArray(): bool
    {
        $unpacked = unpack('N', $this->take(4));
        $count = is_array($unpacked) ? ($unpacked[1] ?? 0) : 0;

        $this->take(max((int) $count, 0) * 8);

        return true;
    }

    private function take(int $bytes): string
    {
        if ($bytes < 0 || $bytes > self::MAX_READ_BLOCK) {
            throw new \DomainException('level.dat could not be decoded: declared block of '.number_format($bytes).' bytes exceeds supported size.');
        }

        if ($this->data === null || $this->offset + $bytes > strlen($this->data)) {
            throw new \DomainException('level.dat could not be decoded: unexpected end of data.');
        }

        $chunk = substr($this->data, $this->offset, $bytes);
        $this->offset += $bytes;

        return $chunk;
    }
}
