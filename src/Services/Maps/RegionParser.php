<?php

declare(strict_types=1);

namespace McToolkit\Services\Maps;

use App\Models\Server;
use McToolkit\Services\FileBridge;

/**
 * Top-down Minecraft region rasterizer (.mca -> RGB buffer).
 *
 * Owns every low-level piece of the map pipeline:
 *
 * 1. {@see self::regionBytes()} downloads `r.{rx}.{rz}.mca` ONCE through FileBridge
 *    (capped by config('minecraft-toolkit.limits.region_fetch_max_bytes'), 8 MiB by
 *    default) and memoizes it under
 *    `storage_path("app/mctoolkit/{uuid_short}/mapcache/{world}/{rx}_{rz}_{mtime}.mca")`.
 *    Repeated tile requests reuse the local copy until the wings mtime changes.
 * 2. The 8 KiB Anvil header is decoded into the 4096-entry sector-offset table
 *    (big-endian uint32 packing `sector_index << 8 | sector_count`).
 * 3. Each chunk record is length-prefixed (int32 BE, value includes the trailing
 *    compression byte) and inflated through the BOUNDED helper below (ceiling
 *    {@see self::MAX_INFLATED_CHUNK_BYTES}): 1 = gzip, 2 = zlib envelope with a
 *    documented fallback chain (raw zlib decode, then `gzinflate` after stripping the
 *    2-byte wrapper), 3 = stored uncompressed. Anything else degrades to fog.
 * 4. A minimal BIG-ENDIAN NBT walker covers tag ids 0..12 (the protocol has twelve
 *    real tags between End and LongArray inclusive; the task brief counts them as
 *    "1..13") with a depth cap of {@see self::MAX_NBT_DEPTH} so hostile/corrupt
 *    payloads cannot recurse unbounded. IntArray/LongArray are supported; 64-bit
 *    values are kept losslessly as PHP ints (two's complement) and split into
 *    32-bit halves before any bit shifting, avoiding unpack() float overflow.
 * 5. Section container layouts are resolved pragmatically because writers differ:
 *    `Level.Sections` (legacy nested), root `Sections`, root lowercase `sections`
 *    (vanilla >= 1.18), and root `Data.Sections`. Pre-1.13 flat chunk format
 *    (nibble-array Blocks/Data/Add) is intentionally NOT supported - such regions
 *    simply stay fogged. Palette block ids come from `Name`; indices are unpacked
 *    from the LSB-first-per-long BitStates longs with bit size
 *    `max(4, ceil(log2(len(palette))))` (the >= 1.16 packing rule).
 * 6. Column colors walk Y descending and stop at the first surface block: air
 *    variants are skipped, glass family is passed through (documented tradeoff -
 *    glass roofs show the ground beneath them), water triggers the documented
 *    WATER_BLEND_FACTOR composite below its underlying ground tone.
 *
 * Honest limits (all painted as {@see self::FOG_COLOR_HEX}): missing/corrupt
 * regions, unsupported compression tags, truncated downloads, empty status-less
 * chunks, and pre-1.18-without-sections variants. The client renders fog pixels
 * identically (#0b0e14) so partially rendered tiles blend seamlessly.
 */
final class RegionParser
{
    /** Sector unit of the Anvil format. */
    public const SECTOR_BYTES = 4096;

    public const CHUNKS_PER_REGION_AXIS = 32;

    /** Constant shared with the canvas layer (blocksPerChunk = 16). */
    public const BLOCKS_PER_CHUNK = 16;

    /** Blocks covered by one whole region axis (32 chunks x 16 blocks). */
    public const BLOCKS_PER_REGION_AXIS = 512;

    /**
     * Fog/backdrop color for never-seen or unrenderable area. Must match the canvas
     * container background exactly (clients treat the heuristic as seamless).
     */
    public const FOG_COLOR_HEX = '#0b0e14';

    /** Neutral stand-in for palette ids missing from resources/data/blockcolors.json. */
    public const UNKNOWN_BLOCK_COLOR = '#777777';

    /** Linear mix weight applied over the ground tone when water sits above it. */
    public const WATER_BLEND_FACTOR = 0.6;

    private const MAX_NBT_DEPTH = 7;

    private const FOG_RGB = [11, 14, 20];

    private const COMPRESSION_NONE = 3;

    /** Deep-water tone used when a column contains ONLY water. */
    private const DEEP_WATER_HEX = '#3156ad';

    /** Hex color cache decoded lazily from resources/data/blockcolors.json. */
    private ?array $colors = null;

    public function __construct(private readonly FileBridge $bridge) {}

    /* ------------------------------------------------------------------ */
    /*  Public rasterizer API                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Renders one region as an S x S grid of packed "R G B" bytes (row-major,
     * north-west corner at [0]).
     *
     * @param  int  $coarse  downscale factor: how many blocks one output pixel spans
     *                          along each axis (valid: 1, 2, 4, 8)
     * @param  int  $yTop    highest world Y considered while walking columns down
     * @return array{rgb:string, side:int, present_chunks:int, total_chunks:int}|null
     *         null when the region cannot be obtained or parsed at all
     */
    public function rasterize(Server $server, string $worldDir, int $regionX, int $regionZ, int $coarse, int $yTop, ?int $mtimeHint = null): ?array
    {
        $bytes = $this->regionBytes($server, $worldDir, $regionX, $regionZ, $mtimeHint);

        if ($bytes === null || strlen($bytes) < self::SECTOR_BYTES * 2) {
            return null;
        }

        $side = intdiv(self::BLOCKS_PER_REGION_AXIS, max(1, $coarse));
        $buffer = str_repeat(chr(self::FOG_RGB[0]).chr(self::FOG_RGB[1]).chr(self::FOG_RGB[2]), $side * $side);

        $present = $this->paintChunks($bytes, $coarse, $yTop, $buffer, $side);

        return [
            'rgb' => $buffer,
            'side' => $side,
            'present_chunks' => $present['parsed'],
            'total_chunks' => $present['candidates'],
        ];
    }

    /**
     * Inline sanity routine used during development builds only (never auto-run).
     * Builds a tiny synthetic chunk NBT blob in-memory, gzips it exactly like wings'
     * region files do, and pushes it through the decode + sections pipeline so a
     * broken decompression chain / NBT walker / key handling surfaces early without
     * a real server nearby. Returns true ONLY when every asserted field matches.
     */
    public static function selfTestDecodingSample(): bool
    {
        // --- craft {"":{"Level":{"Sections":[{Y=5, Palette=[{Name=oak_log}]}]}}} ---
        // Realistic section shape (palette comp + empty BitStates), wire-exact with
        // every compound closed explicitly by its End byte.
        $paletteEntry =                              // list element: compound BODY only (no tag header)
            "\x08\x00\x04Name\x00\x07oak_log"
            ."\x00";                                  // End

        $sectionElement =
            "\x01\x00\x01Y".pack('c', 5)              // TAG_Byte Y = 5
            ."\x09\x00\x07Palette"                    // TAG_List named "Palette"
            .pack('c', 10)                            //   element tag id = Compound
            .pack('N', 1)                             //   count = 1
            .$paletteEntry;

        $rootBlob = "\x0a\x00\x00"                    // root compound, empty name
            ."\x0a\x00\x05Level"                      // TAG_Compound named "Level"
            ."\x09\x00\x08Sections"                   // TAG_List named "Sections"
            .pack('c', 10)                            //   element tag id = Compound
            .pack('N', 1)                             //   length = 1
            .$sectionElement."\x00"                   // section element compound End
            ."\x00";                                  // Level compound End

        $gzipped = @gzencode($rootBlob);

        if (! is_string($gzipped)) {
            return false;
        }

        // Constructed WITHOUT its FileBridge dependency on purpose: the sanity path
        // only exercises pure byte-level helpers, so the probe stays runnable outside
        // a bootstrapped container (plain `php -r`, CI, etc.).
        try {
            $probe = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        } catch (\ReflectionException) {
            return false;
        }

        // Route through the SAME inflate chain (compression tag 1 = gzip).
        $inflated = self::inflateChunkPayload($gzipped, 1);

        if (! is_string($inflated)) {
            return false;
        }

        $root = $probe->decodeChunkNbt($inflated);

        if (! is_array($root) || ! isset($root['Level']['Sections'])) {
            return false;
        }

        $sections = $probe->sectionsOf($root);

        return is_array($root)
            && isset($root['Level']['Sections'][0]['Palette'][0]['Name'])
            && $root['Level']['Sections'][0]['Palette'][0]['Name'] === 'oak_log'
            && count($sections) === 1
            && (($sections[0]['y'] ?? -99) === 5)
            && (($sections[0]['palette_names'][0] ?? null) === 'oak_log');
    }

    /* ------------------------------------------------------------------ */
    /*  Fetching                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Returns the raw `.mca` contents, downloading once and caching locally under
     * storage_path("app/mctoolkit/{uuid_short}/mapcache/{world}/").
     *
     * The filename carries the wings-reported mtime so stale caches are superseded
     * instead of being overwritten mid-request. sanitizeWorldDir() strips path
     * traversal attempts already at this layer (the HTTP controller basenames too).
     */
    private function regionBytes(Server $server, string $worldDir, int $rx, int $rz, ?int $mtimeHint): ?string
    {
        $remotePath = sprintf('%s/region/r.%d.%d.mca', $this->sanitizeSegment($worldDir), $rx, $rz);

        $cacheDirectory = implode(DIRECTORY_SEPARATOR, [
            storage_path('app'),
            'mctoolkit',
            preg_replace('/[^A-Za-z0-9_-]/', '', substr((string) $server->uuid_short, 0, 24)) ?: 'unknown',
            'mapcache',
            $this->sanitizeSegment($worldDir),
        ]);

        $cacheFile = $cacheDirectory.DIRECTORY_SEPARATOR.sprintf(
            '%d_%d_%d.mca',
            $rx,
            $rz,
            $mtimeHint ?? 0,
        );

        if (is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);

            return is_string($cached) && $cached !== '' ? $cached : null;
        }

        try {
            $fetched = $this->bridge->read($server, $remotePath, $this->fetchLimit());
        } catch (\Throwable) {
            return null;
        }

        if ($fetched === null || $fetched === '') {
            return null;
        }

        // Best-effort persistence: a failed disk write must NOT poison rendering.
        if (@mkdir($cacheDirectory, 0775, true) || is_dir($cacheDirectory)) {
            $tmp = $cacheFile.'.'.bin2hex(random_bytes(4)).'.tmp';

            if (@file_put_contents($tmp, $fetched) !== false && @rename($tmp, $cacheFile)) {
                // pruned sibling generations get cleaned opportunistically below.
                $this->pruneStaleGenerations($cacheDirectory, basename($cacheFile));
            }
        }

        return $fetched;
    }

    private function fetchLimit(): int
    {
        return (int) (config('minecraft-toolkit.limits.region_fetch_max_bytes', 8_388_608));
    }

    private function sanitizeSegment(string $segment): string
    {
        $clean = basename(str_replace('\\', '/', trim($segment)));

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $clean) ?: 'world';
    }

    private function pruneStaleGenerations(string $directory, string $keepBasename): void
    {
        [$rxrz, ] = explode('_', $keepBasename.'_');
        $prefix = implode('_', [$rxrz]);

        foreach ((array) @glob($directory.DIRECTORY_SEPARATOR.$prefix.'*_*.mca') as $candidate) {
            if (is_string($candidate) && basename($candidate) !== $keepBasename) {
                @unlink($candidate);
            }
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Region decoding                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * @return array{parsed:int, candidates:int}
     */
    private function paintChunks(string $regionBytes, int $coarse, int $yTop, string &$buffer, int $side): array
    {
        $parsed = 0;
        $candidates = 0;

        $header = unpack('N1024', substr($regionBytes, 0, self::SECTOR_BYTES));

        if ($header === false) {
            return ['parsed' => 0, 'candidates' => 0];
        }

        for ($index = 0; $index < 1024; $index++) {
            $entry = $header[$index + 1];

            if ($entry === 0) {
                continue;
            }

            $sectorOffset = $entry >> 8;
            $sectorCount = $entry & 0xFF;

            if ($sectorOffset < 2 || $sectorCount === 0) {
                continue;
            }

            $candidates++;

            $start = $sectorOffset * self::SECTOR_BYTES;

            if ($start + 5 > strlen($regionBytes)) {
                continue;
            }

            $lengthMeta = unpack('N', substr($regionBytes, $start, 4));
            $length = (int) ($lengthMeta[1] ?? 0);

            // The prefix INCLUDES the trailing compression byte.
            if ($length < 2) {
                continue;
            }

            $payloadLength = $length - 1;
            $compressionByte = ord($regionBytes[$start + 4]);
            $payloadStart = $start + 5;

            if ($payloadStart + $payloadLength > strlen($regionBytes)) {
                $payloadLength = strlen($regionBytes) - $payloadStart;
            }

            if ($payloadLength <= 0) {
                continue;
            }

            $payload = substr($regionBytes, $payloadStart, $payloadLength);
            $inflated = self::inflateChunkPayload($payload, $compressionByte);

            if ($inflated === null) {
                continue;
            }

            $root = $this->decodeChunkNbt($inflated);

            if ($root === null) {
                continue;
            }

            $sections = $this->sectionsOf($root);

            if ($sections === []) {
                continue;
            }

            $status = strval($root['Status'] ?? $root['Level']['Status'] ?? '');
            $isReady = in_array($status, ['minecraft:full', 'full'], true) || $status === '';

            // Chunk-local origin inside the region (0..31 based, negatives wrap).
            $cx = $index % 32;
            $cz = intdiv($index, 32);

            $this->paintChunkColumns(
                $sections,
                $cx,
                $cz,
                $coarse,
                min(320, max(-64, $yTop)),
                $isReady,
                $buffer,
                $side,
            );

            $parsed++;
        }

        return ['parsed' => $parsed, 'candidates' => $candidates];
    }

    /**
     * Documented inflate chain: 1=gzip, 2=zlib envelope (raw decode first, then
     * raw-deflate fallbacks after stripping the two leading zlib bytes / trying the
     * whole buffer), 3=stored. Anything else (future tags, corruption) returns null
     * so the chunk falls back to fog.
     *
     * Every inflate runs BOUNDED ({@see self::MAX_INFLATED_CHUNK_BYTES}): a hostile
     * or corrupt .mca chunk record cannot expand beyond the ceiling inside memory
     * (decompression-bomb guard for imported/uploaded worlds).
     */
    private static function inflateChunkPayload(string $payload, int $compressionByte): ?string
    {
        try {
            $decoded = match ($compressionByte) {
                1 => self::boundedInflate($payload, ZLIB_ENCODING_GZIP),
                2 => self::boundedInflate($payload, ZLIB_ENCODING_DEFLATE),
                self::COMPRESSION_NONE => $payload,
                default => null,
            };

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }

            if ($compressionByte === 2) {
                $stripped = self::boundedInflate(substr($payload, 2), ZLIB_ENCODING_RAW);

                if (is_string($stripped) && $stripped !== '') {
                    return $stripped;
                }

                $rawInflate = self::boundedInflate($payload, ZLIB_ENCODING_RAW);

                if (is_string($rawInflate) && $rawInflate !== '') {
                    return $rawInflate;
                }
            }
        } catch (\Throwable) {
            // PhP inflating functions throw on corrupt streams depending on build; fall through.
        }

        return null;
    }

    /** Decompression-bomb ceiling for ONE chunk record (~a full chunk column worth many times over). */
    private const MAX_INFLATED_CHUNK_BYTES = 16_777_216;

    /**
     * Streaming inflate with an output-size ceiling enforced WHILE feeding input
     * chunks (not after allocating the whole expansion like gzdecode/zlib_decode do).
     *
     * @return string|null inflated bytes; null on corruption, emptiness or ceiling breach
     */
    private static function boundedInflate(string $payload, int $encoding, int $maxBytes = self::MAX_INFLATED_CHUNK_BYTES): ?string
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
    /*  Minimal big-endian NBT                                             */
    /* ------------------------------------------------------------------ */

    /**
     * Decodes the chunk-root compound (tag id 10 + length-prefixed root name +
     * compound body). Returns `name => value` recursively; tag id 0 closes scopes.
     *
     * Tag coverage: 1 Byte .. 12 LongArray (ids referenced as "1..13 inclusive"
     * in the design brief; End is counted there as well). Depth is capped at
     * MAX_NBT_DEPTH; deeper compounds/lists are dropped rather than recursed.
     */
    private function decodeChunkNbt(string $binary): ?array
    {
        $len = strlen($binary);

        if ($len < 3 || ord($binary[0]) !== 10) {
            return null;
        }

        $nameLen = unpack('n', $binary[1].$binary[2])[1];
        $pos = 3 + $nameLen;

        if ($pos > $len) {
            return null;
        }

        $value = [];

        while ($pos < $len) {
            $tagId = ord($binary[$pos++]);

            if ($tagId === 0) {
                break;
            }

            $keyLen = unpack('n', $binary[$pos].$binary[$pos + 1])[1];
            $key = substr($binary, $pos + 2, $keyLen);
            $pos += 2 + $keyLen;

            [$value[$key], $pos] = $this->readTagPayload($binary, $pos, $tagId, 1);
        }

        return $value;
    }

    /**
     * Reads ONE tag payload; returns [value, nextPosition].
     *
     * @return array{0:mixed, 1:int}
     */
    private function readTagPayload(string $binary, int $pos, int $tagId, int $depth): array
    {
        $len = strlen($binary);

        if ($depth > self::MAX_NBT_DEPTH || $pos > $len) {
            return [null, $len];
        }

        switch ($tagId) {
            case 1: // Byte (Java signed byte -> PHP signed int)
                if ($pos >= $len) {
                    return [0, $len];
                }

                $raw = unpack('C', $binary[$pos])[1];

                return [$raw > 127 ? $raw - 256 : $raw, $pos + 1];

            case 2: // Short
                if ($pos + 2 > $len) {
                    return [0, $len];
                }

                $raw = unpack('n', substr($binary, $pos, 2))[1];

                return [$raw > 32767 ? $raw - 65536 : $raw, $pos + 2];

            case 3: // Int
                if ($pos + 4 > $len) {
                    return [0, $len];
                }

                $raw = unpack('N', substr($binary, $pos, 4))[1];

                return [$raw > 2147483647 ? $raw - 4294967296 : $raw, $pos + 4];

            case 4: // Long (kept as PHP int, two's complement identical to the wire form)
                if ($pos + 8 > $len) {
                    return [0, $len];
                }

                [$hi, $lo] = array_values(unpack('N2', substr($binary, $pos, 8)));
                $value = ($hi << 32) | $lo; // $hi<<32 wraps negative on PHP ints by design.

                return [$value, $pos + 8];

            case 5: // Float (format G = big-endian float32)
                if ($pos + 4 > $len) {
                    return [0.0, $len];
                }

                return [(float) unpack('G', substr($binary, $pos, 4))[1], $pos + 4];

            case 6: // Double
                if ($pos + 8 > $len) {
                    return [0.0, $len];
                }

                return [(float) (unpack('E', substr($binary, $pos, 8))[1] ?? 0.0), $pos + 8];

            case 7: // ByteArray
                if ($pos + 4 > $len) {
                    return ['', $len];
                }

                $size = unpack('N', substr($binary, $pos, 4))[1];
                $bodyStart = $pos + 4;

                if ($bodyStart + $size > $len) {
                    $size = $len - $bodyStart;
                }

                return [substr($binary, $bodyStart, $size), $bodyStart + $size];

            case 8: // String (modified UTF-8, read verbatim; renderer compares ASCII keys only)
                if ($pos + 2 > $len) {
                    return ['', $len];
                }

                $strLen = unpack('n', substr($binary, $pos, 2))[1];
                $bodyStart = $pos + 2;
                $safe = min($strLen, $len - $bodyStart);

                return [substr($binary, $bodyStart, $safe), $bodyStart + $strLen];

            case 9: // List
                if ($pos + 5 > $len) {
                    return [[], $len];
                }

                $elementTag = ord($binary[$pos]);
                $countRaw = unpack('N', substr($binary, $pos + 1, 4))[1];
                $count = $countRaw > 2147483647 ? $countRaw - 4294967296 : $countRaw;
                $cursor = $pos + 5;

                if ($count <= 0 || $elementTag === 0 || $elementTag > 12) {
                    return [[], $cursor];
                }

                $items = [];
                $cap = min($count, 131072); // hard safety valve; real sections stay far below

                for ($i = 0; $i < $cap && $cursor < $len; $i++) {
                    [$items[], $cursor] = $this->readTagPayload($binary, $cursor, $elementTag, $depth + 1);
                }

                return [$items, $cursor];

            case 10: // Compound
                $out = [];
                $cursor = $pos;

                while ($cursor < $len) {
                    $innerTag = ord($binary[$cursor++]);

                    if ($innerTag === 0) {
                        break;
                    }

                    // Defensive: ids beyond the known set cannot be skipped soundly,
                    // so the scope is truncated instead of risking a stalled cursor.
                    if ($innerTag > 12 || $cursor + 2 > $len) {
                        break;
                    }

                    $keyLen = unpack('n', substr($binary, $cursor, 2))[1];
                    $key = substr($binary, $cursor + 2, $keyLen);
                    $cursor += 2 + $keyLen;

                    [$out[$key], $cursor] = $this->readTagPayload($binary, $cursor, $innerTag, $depth + 1);
                }

                return [$out, min($len, max($pos, $cursor))];

            case 11: // IntArray
                if ($pos + 4 > $len) {
                    return [[], $len];
                }

                $count = unpack('N', substr($binary, $pos, 4))[1];
                $bodyStart = $pos + 4;
                $available = intdiv(max(0, $len - $bodyStart), 4);
                $take = min($count, $available, 65536);

                $values = $take > 0 ? array_values(unpack('N'.$take, substr($binary, $bodyStart, $take * 4))) : [];
                $values = array_map(static fn ($raw) => $raw > 2147483647 ? $raw - 4294967296 : $raw, $values);

                return [$values, $bodyStart + $count * 4];

            case 12: // LongArray
                if ($pos + 4 > $len) {
                    return [[], $len];
                }

                $count = unpack('N', substr($binary, $pos, 4))[1];
                $bodyStart = $pos + 4;
                $available = intdiv(max(0, $len - $bodyStart), 8);
                $take = min($count, $available, 262144);

                $values = [];

                if ($take > 0) {
                    $raw = unpack('N'.($take * 2), substr($binary, $bodyStart, $take * 8));

                    for ($i = 1; $i <= $take * 2; $i += 2) {
                        $hi = $raw[$i];
                        $lo = $raw[$i + 1];
                        $values[] = ($hi << 32) | $lo;
                    }
                }

                return [$values, $bodyStart + $count * 8];

            default: // Future/unexpected tag ids: the value is dropped and the cursor is
                     // left untouched; every caller bounds its own iteration counts.
                return [null, $pos];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Sections dual-path resolution                                      */
    /* ------------------------------------------------------------------ */

    /**
     * Pragmatic dual-path reader: legacy writers nest sections under `Level`,
     * vanilla >= 1.18 puts lowercase `sections` at the root, other writers emit
     * capitalized `Sections` or wrap everything in `Data`. Detection is by key
     * presence; first hit wins.
     *
     * Normalized rows: [{y:int, palette_names:list<string>, state_words:list<int>}]
     * where state_words flattens EVERY BitState long into its two 32-bit halves
     * ([hi, lo, hi, lo, ...]) so bit windows never require 64-bit shifting.
     * Sections are returned sorted by Y descending.
     *
     * @return list<array{y:int, palette_names:list<string>, state_words:list<int>}>
     */
    private function sectionsOf(array $root): array
    {
        $candidates = [
            $root['Level']['Sections'] ?? null,
            $root['Level']['sections'] ?? null,
            $root['Sections'] ?? null,
            $root['sections'] ?? null,
            $root['Data']['Sections'] ?? null,
            $root['Data']['sections'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (! is_array($raw) || $raw === []) {
                continue;
            }

            $rows = [];

            foreach ($raw as $section) {
                if (! is_array($section)) {
                    continue;
                }

                $y = is_int($section['Y'] ?? null) ? $section['Y'] : (is_numeric($section['Y'] ?? null) ? intval($section['Y']) : null);

                if ($y === null) {
                    continue;
                }

                $names = [];

                if (isset($section['Palette']) && is_array($section['Palette'])) {
                    foreach ($section['Palette'] as $block) {
                        $names[] = $this->normalizeBlockName(is_array($block) ? strval($block['Name'] ?? '') : '');
                    }
                }

                $words = $this->flattenBitStates($section['BlockStates'] ?? null);

                $rows[] = [
                    'y' => $y,
                    'palette_names' => $names,
                    'state_words' => $words,
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);

            return $rows;
        }

        return [];
    }

    /**
     * Normalizes `minecraft:oak_leaves` -> `oak_leaves` and lowercases anything
     * coming from non-vanilla namespaces (modded blocks collapse onto their
     * cleaned identifier instead of crashing the renderer).
     */
    private function normalizeBlockName(string $id): string
    {
        if ($id === '' || $id === 'minecraft:') {
            return '';
        }

        if (str_contains($id, ':')) {
            [, $id] = explode(':', $id, 2);
        }

        return strtolower(preg_replace('/[^a-zA-Z0-9_]+/', '_', $id) ?? '');
    }

    /**
     * Turns the raw BitStates payload (List of Longs) into the flat 32-bit word
     * array the bit-unpacker consumes.
     *
     * Word order matters: Java long bit 0 (numeric LSB) lives in the LOW half,
     * so each long contributes [lo, hi] — that keeps the flattened stream aligned
     * with the global bit numbering (word k covers bits [k*32 .. k*32+31)).
     *
     * @return list<int>
     */
    private function flattenBitStates(mixed $states): array
    {
        if (! is_array($states) || $states === []) {
            return [];
        }

        $words = [];

        foreach ($states as $long) {
            if (! is_int($long)) {
                continue;
            }

            $words[] = $long & 0xFFFFFFFF;
            $words[] = ($long >> 32) & 0xFFFFFFFF;
        }

        return $words;
    }

    /**
     * Extracts one bit-window (LSB-first per Java long semantics, matching the
     * >= 1.16 palette packing) from the flattened word array. Window widths are
     * always << 32 bits here (palettes hold far fewer than 2^32 blocks), so no
     * 64-bit arithmetic is required anywhere in the renderer.
     */
    private static function readWindow(array $words, int $startBit, int $width): int
    {
        if ($width <= 0) {
            return 0;
        }

        $value = 0;
        $consumed = 0;

        while ($consumed < $width) {
            $absolute = $startBit + $consumed;
            $wordIndex = intdiv($absolute, 32);
            $offset = $absolute % 32;
            $take = min(32 - $offset, $width - $consumed);

            $slice = (($words[$wordIndex] ?? 0) >> $offset) & ((1 << $take) - 1);
            $value |= $slice << $consumed;
            $consumed += $take;
        }

        return $value;
    }

    /* ------------------------------------------------------------------ */
    /*  Column painting                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Paints the coarse-sampled column grid of one chunk onto the buffer.
     *
     * Mapping: relative chunk coords (cx,cz) live inside the 32x32 grid; sampled
     * block positions advance by `$coarse` horizontally so each output pixel owns
     * the topmost visible block at its south-west sample anchor (documented
     * nearest-block heuristic for downscaled tiles).
     */
    private function paintChunkColumns(
        array $sections,
        int $cx,
        int $cz,
        int $coarse,
        int $yTopCap,
        bool $statusReady,
        string &$buffer,
        int $side,
    ): void {
        if (! $statusReady) {
            return; // unpainted candidates simply remain fog.
        }

        // Pre-resolve kind codes once per section (air=0 skipped, water blends,
        // glass passes through, everything else terminates the walk opaquely).
        $paletteKind = [];

        foreach ($sections as &$section) {
            $kinds = [];

            foreach ($section['palette_names'] as $idx => $name) {
                $kinds[$idx] = self::classify($name);
            }

            $section['kinds'] = $kinds;
            $section['color_rgb'] = $this->rgbTableFor($section['palette_names']);
        }

        unset($section);

        $step = max(1, $coarse);

        for ($localZ = 0; $localZ < self::BLOCKS_PER_CHUNK; $localZ += $step) {
            for ($localX = 0; $localX < self::BLOCKS_PER_CHUNK; $localX += $step) {
                $columnRgb = $this->columnColor($sections, $localX, $localZ, $yTopCap);

                if ($columnRgb === null) {
                    continue; // leave fog underneath.
                }

                // Global block coordinates within the region grid.
                $gridX = $cx * self::BLOCKS_PER_CHUNK + $localX;
                $gridZ = $cz * self::BLOCKS_PER_CHUNK + $localZ;

                if ($gridX >= self::BLOCKS_PER_REGION_AXIS || $gridZ >= self::BLOCKS_PER_REGION_AXIS) {
                    continue;
                }

                $px = intdiv($gridX, $coarse);
                $pz = intdiv($gridZ, $coarse);

                if ($px >= $side || $pz >= $side) {
                    continue;
                }

                $offset = ($pz * $side + $px) * 3;
                $buffer[$offset] = chr($columnRgb[0]);
                $buffer[$offset + 1] = chr($columnRgb[1]);
                $buffer[$offset + 2] = chr($columnRgb[2]);
            }
        }
    }

    /** Kind classification: 0 air (walk past), 1 opaque surface, 2 water blend, 3 see-through pass. */
    private static function classify(string $name): int
    {
        if ($name === '') {
            return 0;
        }

        if (in_array($name, ['air', 'void_air', 'cave_air'], true)) {
            return 0;
        }

        if (str_contains($name, 'water') || $name === 'bubble_column' || $name === 'sea_grass' || $name === 'seagrass' || $name === 'kelp') {
            return 2;
        }

        if (str_contains($name, 'glass')) {
            return 3;
        }

        return 1;
    }

    /**
     * Walks one column downwards until a terminating block appears. Returns the
     * final RGB triplet or null (leave-fog contract).
     *
     * Water behavior: the FIRST water block met stores the tint; the walk continues
     * beneath it. When ground eventually shows up the final color composites
     * WATER_BLEND_FACTOR of the recorded water tint OVER the ground tone
     * (documented approximation of looking into shallow water). When the whole
     * column is water, the deep-water tone paints instead.
     *
     * @return array{0:int, 1:int, 2:int}|null
     */
    private function columnColor(array $sections, int $localX, int $localZ, int $yTopCap): ?array
    {
        $waterAbove = null;
        $yScanStarted = false;
        $encountered = false; // did we meet ANY informative (non-air-resolved) block?

        foreach ($sections as $section) {
            $baseY = $section['y'] * 16;
            $paletteNames = $section['palette_names'];
            $kinds = $section['kinds'] ?? [];
            $words = $section['state_words'];

            if ($paletteNames === [] || $words === []) {
                continue;
            }

            $bitSize = max(4, (int) ceil(log(max(count($paletteNames), 1), 2)));

            for ($yLocal = 15; $yLocal >= 0; $yLocal--) {
                $worldY = $baseY + $yLocal;

                if (!$yScanStarted && $worldY > $yTopCap) {
                    continue; // clamp the very top of tall section stacks.
                }

                $yScanStarted = true;

                // Anvil ordering inside a section: Y-major, then Z, then X.
                // Packing address: each Java long carries floor(64/bitSize) entries;
                // leftover bits per long are PADDING skipped before the next long.
                $index = ($yLocal * 256) + ($localZ * 16) + $localX;
                $entriesPerLong = intdiv(64, $bitSize);
                $startBit = intdiv($index, $entriesPerLong) * 64 + ($index % $entriesPerLong) * $bitSize;
                $entry = self::readWindow($words, $startBit, $bitSize);

                if ($entry >= count($paletteNames)) {
                    continue;
                }

                $kind = $kinds[$entry] ?? 1;

                if ($kind === 0) {
                    continue;
                }

                $encountered = true; // water & opaque both count; glass alone does not.

                if ($kind === 3) {
                    continue; // glass roof: keep looking beneath (documented).
                }

                $rgb = $section['color_rgb'][$entry] ?? self::hexToRgb(self::UNKNOWN_BLOCK_COLOR);

                if ($kind === 2) {
                    $waterAbove ??= $rgb;

                    continue; // carry on below for underwater ground tone.
                }

                return $waterAbove === null
                    ? $rgb
                    : self::blendWater($waterAbove, $rgb);
            }
        }

        if (!$encountered) {
            return null; // nothing informative in this column -> keep fog (leave-fog contract)
        }

        return $waterAbove === null ? self::hexToRgb(self::UNKNOWN_BLOCK_COLOR) : self::blendWater($waterAbove, self::hexToRgb(self::UNKNOWN_BLOCK_COLOR));
    }

    /**
     * Cached hex -> rgb triplets per palette entry (UNKNOWN_BLOCK_COLOR fallback).
     *
     * @return list<array{0:int,1:int,2:int}>
     */
    private function rgbTableFor(array $names): array
    {
        $table = [];
        $known = $this->colors();

        foreach ($names as $name) {
            $hex = $known[$name] ?? null;

            if (! is_string($hex) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $hex)) {
                $hex = $name === '' ? self::FOG_COLOR_HEX : self::UNKNOWN_BLOCK_COLOR;
            }

            $table[] = self::hexToRgb($hex);
        }

        return $table;
    }

    /**
     * @return array<string,string>
     */
    private function colors(): array
    {
        if ($this->colors === null) {
            $path = dirname(__DIR__, 3).'/resources/data/blockcolors.json';

            $decoded = [];

            if (is_file($path)) {
                try {
                    $decoded = json_decode((string) file_get_contents($path), true, 4, JSON_THROW_ON_ERROR) ?? [];
                } catch (\JsonException) {
                    $decoded = [];
                }
            }

            // Tolerate the _meta envelope; scalars only survive.
            $this->colors = array_filter(
                is_array($decoded) ? $decoded : [],
                static fn (mixed $entry): bool => is_string($entry) && str_starts_with($entry, '#'),
            );
        }

        return $this->colors;
    }

    private static function hexToRgb(string $hex): array
    {
        $value = (int) hexdec(substr(trim($hex, '#'), 0, 6));

        return [($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];
    }

    /**
     * Composite blend: `factor` amount of the water tint layered over the ground
     * tone (WATER_BLEND_FACTOR = 0.6 per design note).
     *
     * @param  array{0:int,1:int,2:int}  $water
     * @param  array{0:int,1:int,2:int}  $ground
     * @return array{0:int,1:int,2:int}
     */
    private static function blendWater(array $water, array $ground): array
    {
        $factor = self::WATER_BLEND_FACTOR;
        $under = 1.0 - $factor;

        return [
            (int) min(255, round($water[0] * $factor + $ground[0] * $under)),
            (int) min(255, round($water[1] * $factor + $ground[1] * $under)),
            (int) min(255, round($water[2] * $factor + $ground[2] * $under)),
        ];
    }
}
