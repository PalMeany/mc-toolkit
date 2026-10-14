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
 * 1. {@see self::regionBytes()} downloads `<regionDir>/r.{rx}.{rz}.mca` ONCE through
 *    FileBridge (capped by config('minecraft-toolkit.limits.region_fetch_max_bytes'),
 *    8 MiB by default) and mirrors it under
 *    `storage_path("app/mctoolkit/{uuid_short}/mapcache/{regionDirKey}/{rx}_{rz}_{mtime}.mca")`.
 *    The wings mtime is part of the name, so a changed region is fetched again and the
 *    older generation of THAT region (same rx/rz only) is pruned.
 * 2. The 8 KiB Anvil header is decoded into the 1024-entry sector table
 *    (big-endian uint32 packing `sector_offset << 8 | sector_count`).
 * 3. Each chunk record is length-prefixed (int32 BE, value includes the trailing
 *    compression byte) and inflated through a BOUNDED helper: 1 = gzip, 2 = zlib,
 *    3 = stored. LZ4 (4) and external `.mcc` chunks (0x80 flag) degrade to fog.
 * 4. A SELECTIVE big-endian NBT decoder materializes only what the renderer needs
 *    (DataVersion, Status, the section list and, inside each section, Y + block
 *    states); every other subtree (block entities, entities, structures, heightmaps…)
 *    is skipped arithmetically, so deep chests or jigsaw structures can neither blow
 *    memory up nor cut the parse short.
 * 5. Section layouts: `sections[].block_states.{palette,data}` (>= 1.18) and
 *    `Level.Sections[].{Palette,BlockStates}` (1.13 – 1.17); 26.1+ palette entries are
 *    plain id strings or `{id, properties}` ({@see self::paletteEntryId()}). A single-entry palette has
 *    no data array (the whole section is that block). Bits per entry are
 *    `max(4, ceil(log2(palette size)))`; since 1.16 (DataVersion 2529) entries never
 *    span two longs, before that they do — the chunk's DataVersion picks the rule.
 *    Pre-1.13 numeric-id chunks have no palette and stay fogged.
 * 6. Column colors walk Y descending and stop at the first surface block: air is
 *    skipped, glass and small decorations (grass, flowers, torches, rails…) are passed
 *    through, water triggers the documented WATER_BLEND_FACTOR composite.
 *
 * Honest limits (all painted as {@see self::FOG_COLOR_HEX}): missing/corrupt regions,
 * unsupported compression, truncated downloads, regions above the fetch cap,
 * not-yet-full proto chunks and pre-1.13 chunks.
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

    /** First DataVersion (20w17a / 1.16) whose packed longs no longer span entries. */
    public const NON_SPANNING_DATA_VERSION = 2529;

    /** Depth cap for materialized tags; deeper wanted data is skipped, never truncating. */
    private const MAX_NBT_DEPTH = 12;

    /** Nesting cap while skipping; real chunks stay far below, hostile ones just end the scope. */
    private const MAX_SKIP_DEPTH = 256;

    /** More sections than this per chunk are skipped (vanilla tops out at 26). */
    private const MAX_SECTIONS = 64;

    private const FOG_RGB = [11, 14, 20];

    private const COMPRESSION_NONE = 3;

    /** Chunk-root keys the renderer reads; everything else is skipped. */
    private const ROOT_KEEP = ['DataVersion', 'Status', 'sections', 'Sections', 'Level', 'Data'];

    /** Keys read inside the legacy `Level` (or `Data`) wrapper compound. */
    private const LEVEL_KEEP = ['Status', 'Sections', 'sections', 'DataVersion'];

    /** Keys read inside one section compound. */
    private const SECTION_KEEP = ['Y', 'block_states', 'Palette', 'BlockStates'];

    /** Small/transparent blocks the column walk looks through. */
    private const PASS_THROUGH = [
        'short_grass', 'grass', 'tall_grass', 'fern', 'large_fern', 'dead_bush', 'vine',
        'cave_vines', 'cave_vines_plant', 'glow_lichen', 'hanging_roots', 'spore_blossom',
        'torch', 'wall_torch', 'soul_torch', 'soul_wall_torch', 'redstone_torch',
        'redstone_wall_torch', 'lever', 'tripwire', 'tripwire_hook', 'string', 'ladder',
        'rail', 'powered_rail', 'detector_rail', 'activator_rail', 'redstone_wire',
        'light', 'barrier', 'structure_void', 'dandelion', 'poppy', 'blue_orchid',
        'allium', 'azure_bluet', 'red_tulip', 'orange_tulip', 'white_tulip', 'pink_tulip',
        'oxeye_daisy', 'cornflower', 'lily_of_the_valley', 'wither_rose', 'torchflower',
        'sunflower', 'lilac', 'rose_bush', 'peony', 'pitcher_plant', 'brown_mushroom',
        'red_mushroom', 'sweet_berry_bush', 'nether_sprouts', 'warped_roots',
        'crimson_roots', 'fire', 'soul_fire', 'cobweb', 'scaffolding',
    ];

    /** Suffixes of small/transparent block families (saplings, signs, buttons…). */
    private const PASS_THROUGH_SUFFIXES = [
        '_sapling', '_button', '_sign', '_wall_sign', '_hanging_sign', '_wall_hanging_sign',
        '_banner', '_wall_banner', '_torch', '_candle', '_pane',
    ];

    /** Suffixes stripped to find a base material color (oak_slab -> oak_planks…). */
    private const SHAPE_SUFFIXES = [
        '_slab', '_stairs', '_wall', '_fence_gate', '_fence', '_door', '_trapdoor',
        '_pressure_plate', '_carpet',
    ];

    /** Deep-water tone used when a column contains ONLY water. */
    private const DEEP_WATER_HEX = '#3156ad';

    /** Hex color table decoded lazily from resources/data/blockcolors.json. */
    private ?array $colors = null;

    /** @var array<string, array{0:int,1:int,2:int}> resolved per block name */
    private array $rgbCache = [];

    public function __construct(private readonly FileBridge $bridge) {}

    /* ------------------------------------------------------------------ */
    /*  Public rasterizer API                                              */
    /* ------------------------------------------------------------------ */

    /**
     * Renders one region as an S x S grid of packed "R G B" bytes (row-major,
     * north-west corner at [0]).
     *
     * @param  string  $regionDir  server-relative region folder, e.g. `world/region`,
     *                             `world_nether/DIM-1/region` or (26.1+)
     *                             `world/dimensions/minecraft/the_nether/region` (built by MapTileService)
     * @param  int  $coarse  downscale factor: how many blocks one output pixel spans
     *                          along each axis (valid: 1, 2, 4, 8)
     * @param  int  $yTop    highest world Y considered while walking columns down
     * @return array{rgb:string, side:int, present_chunks:int, total_chunks:int}|null
     *         null when the region cannot be obtained or parsed at all
     */
    public function rasterize(Server $server, string $regionDir, int $regionX, int $regionZ, int $coarse, int $yTop, ?int $mtimeHint = null): ?array
    {
        $bytes = $this->regionBytes($server, $regionDir, $regionX, $regionZ, $mtimeHint);

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

    /** Deletes every locally mirrored `.mca` of one region folder (Redraw). */
    public function purgeMirror(Server $server, string $regionDir): void
    {
        $directory = $this->cacheDirectory($server, $regionDir);

        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*.mca') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
    }

    /**
     * Inline sanity routine used during development builds only (never auto-run).
     * Builds tiny synthetic chunks in both section layouts (legacy `Level.Sections` +
     * `Palette`, and >= 1.18 root `sections` + `block_states` with a single-entry
     * palette), gzips them like region files do and pushes them through the decode +
     * sections pipeline. Returns true ONLY when every asserted field matches.
     */
    public static function selfTestDecodingSample(): bool
    {
        $paletteEntry = "\x08\x00\x04Name\x00\x11minecraft:oak_log"."\x00";

        // Legacy: {"":{Level:{Sections:[{Y:5b, Palette:[{Name:oak_log}], BlockStates:[L;0L x256]}]}}}
        $legacySection =
            "\x01\x00\x01Y".pack('c', 5)
            ."\x09\x00\x07Palette".pack('c', 10).pack('N', 1).$paletteEntry
            ."\x0c\x00\x0bBlockStates".pack('N', 256).str_repeat("\x00", 256 * 8)
            ."\x00";

        $legacy = "\x0a\x00\x00"
            ."\x03\x00\x0bDataVersion".pack('N', 2586)
            ."\x0a\x00\x05Level"
            ."\x09\x00\x08Sections".pack('c', 10).pack('N', 1).$legacySection
            ."\x00"
            ."\x00";

        // Modern: {"":{DataVersion:3953, block_entities:{a:{b:{…{h:{i:1}}}}},
        //           sections:[{Y:-4b, block_states:{palette:[{Name:oak_log}]}}]}} — a deep
        //           unrelated subtree BEFORE sections must not cut the parse short.
        $deep = "\x0a\x00\x01a"."\x0a\x00\x01b"."\x0a\x00\x01c"."\x0a\x00\x01d"."\x0a\x00\x01e"
            ."\x0a\x00\x01f"."\x0a\x00\x01g"."\x0a\x00\x01h"."\x03\x00\x01i".pack('N', 1)
            .str_repeat("\x00", 8);

        $modern = "\x0a\x00\x00"
            ."\x03\x00\x0bDataVersion".pack('N', 3953)
            ."\x0a\x00\x0eblock_entities".$deep."\x00"
            ."\x09\x00\x08sections".pack('c', 10).pack('N', 1)
            ."\x01\x00\x01Y".pack('c', -4)
            ."\x0a\x00\x0cblock_states"
            ."\x09\x00\x07palette".pack('c', 10).pack('N', 1).$paletteEntry
            ."\x00"
            ."\x00"
            ."\x00";

        try {
            $probe = (new \ReflectionClass(self::class))->newInstanceWithoutConstructor();
        } catch (\ReflectionException) {
            return false;
        }

        foreach ([[$legacy, 5, false], [$modern, -4, true]] as [$blob, $expectedY, $expectUniform]) {
            $gzipped = @gzencode($blob);

            if (! is_string($gzipped)) {
                return false;
            }

            $inflated = self::inflateChunkPayload($gzipped, 1);

            if (! is_string($inflated)) {
                return false;
            }

            $root = $probe->decodeChunkNbt($inflated);

            if (! is_array($root)) {
                return false;
            }

            $sections = $probe->sectionsOf($root);

            if (count($sections) !== 1
                || $sections[0]['y'] !== $expectedY
                || ($sections[0]['palette_names'][0] ?? null) !== 'oak_log'
                || $sections[0]['uniform'] !== $expectUniform) {
                return false;
            }
        }

        return true;
    }

    /* ------------------------------------------------------------------ */
    /*  Fetching                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Returns the raw `.mca` contents, downloading once per wings mtime and mirroring
     * locally (see class doc). The region dir is built server-side from validated parts;
     * it is re-checked here so no `..` segment ever reaches wings or the local disk.
     */
    private function regionBytes(Server $server, string $regionDir, int $rx, int $rz, ?int $mtimeHint): ?string
    {
        if (! self::isSafeRelativePath($regionDir)) {
            return null;
        }

        $remotePath = sprintf('/%s/r.%d.%d.mca', trim($regionDir, '/'), $rx, $rz);
        $cacheDirectory = $this->cacheDirectory($server, $regionDir);
        $generation = max(0, $mtimeHint ?? 0);

        $cacheFile = $cacheDirectory.DIRECTORY_SEPARATOR.sprintf('%d_%d_%d.mca', $rx, $rz, $generation);

        // Without an mtime the mirror cannot tell fresh from stale: always re-fetch.
        if ($generation > 0 && is_file($cacheFile)) {
            $cached = @file_get_contents($cacheFile);

            if (is_string($cached) && $cached !== '') {
                return $cached;
            }
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
        if ($generation > 0 && (@mkdir($cacheDirectory, 0775, true) || is_dir($cacheDirectory))) {
            $tmp = $cacheFile.'.'.bin2hex(random_bytes(4)).'.tmp';

            if (@file_put_contents($tmp, $fetched) !== false && @rename($tmp, $cacheFile)) {
                $this->pruneStaleGenerations($cacheDirectory, $rx, $rz, basename($cacheFile));
            } else {
                @unlink($tmp);
            }
        }

        return $fetched;
    }

    private function fetchLimit(): int
    {
        return (int) (config('minecraft-toolkit.limits.region_fetch_max_bytes', 8_388_608));
    }

    /** Local mirror folder of one region dir: readable prefix + hash (never a raw path). */
    private function cacheDirectory(Server $server, string $regionDir): string
    {
        $readable = substr(preg_replace('/[^A-Za-z0-9_-]+/', '_', trim($regionDir, '/')) ?? '', 0, 48);

        return implode(DIRECTORY_SEPARATOR, [
            storage_path('app'),
            'mctoolkit',
            preg_replace('/[^A-Za-z0-9_-]/', '', substr((string) $server->uuid_short, 0, 24)) ?: 'unknown',
            'mapcache',
            ($readable !== '' ? $readable : 'region').'-'.substr(sha1($regionDir), 0, 10),
        ]);
    }

    /** Drops older mirrors of THIS region only (`{rx}_{rz}_*.mca`). */
    private function pruneStaleGenerations(string $directory, int $rx, int $rz, string $keepBasename): void
    {
        foreach ((array) @glob($directory.DIRECTORY_SEPARATOR.sprintf('%d_%d_*.mca', $rx, $rz)) as $candidate) {
            if (is_string($candidate) && basename($candidate) !== $keepBasename) {
                @unlink($candidate);
            }
        }
    }

    /** Relative path made of real names only: no empty, `.`/`..` or backslash segments. */
    private static function isSafeRelativePath(string $path): bool
    {
        $trimmed = trim($path, '/');

        if ($trimmed === '' || str_contains($trimmed, '\\') || str_contains($trimmed, "\0")) {
            return false;
        }

        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return true;
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
        $regionLength = strlen($regionBytes);

        $header = unpack('N1024', substr($regionBytes, 0, self::SECTOR_BYTES));

        if ($header === false) {
            return ['parsed' => 0, 'candidates' => 0];
        }

        $yTopCap = min(320, max(-64, $yTop));

        for ($index = 0; $index < 1024; $index++) {
            $entry = (int) ($header[$index + 1] ?? 0);

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

            if ($start + 5 > $regionLength) {
                continue;
            }

            $length = (int) (unpack('N', substr($regionBytes, $start, 4))[1] ?? 0);

            // The prefix INCLUDES the trailing compression byte.
            if ($length < 2) {
                continue;
            }

            $payloadLength = min($length - 1, $regionLength - ($start + 5));

            if ($payloadLength <= 0) {
                continue;
            }

            $compressionByte = ord($regionBytes[$start + 4]);
            $inflated = self::inflateChunkPayload(substr($regionBytes, $start + 5, $payloadLength), $compressionByte);

            if ($inflated === null) {
                continue;
            }

            try {
                $root = $this->decodeChunkNbt($inflated);
            } catch (\Throwable) {
                $root = null; // one damaged chunk must not cost the whole region
            }

            unset($inflated);

            if ($root === null) {
                continue;
            }

            $sections = $this->sectionsOf($root);

            if ($sections === []) {
                continue;
            }

            $status = $root['Status'] ?? ($root['Level']['Status'] ?? '');
            $status = is_string($status) ? $status : '';

            if (! in_array($status, ['minecraft:full', 'full', 'fullchunk', 'postprocessed', ''], true)) {
                continue; // proto chunk still generating: leave fog
            }

            $dataVersion = $root['DataVersion'] ?? ($root['Level']['DataVersion'] ?? null);
            $spanning = is_int($dataVersion) && $dataVersion < self::NON_SPANNING_DATA_VERSION;

            // Chunk-local origin inside the region (index = x + z * 32).
            $cx = $index % 32;
            $cz = intdiv($index, 32);

            $this->paintChunkColumns($sections, $cx, $cz, $coarse, $yTopCap, $spanning, $buffer, $side);

            $parsed++;
        }

        return ['parsed' => $parsed, 'candidates' => $candidates];
    }

    /**
     * Documented inflate chain: 1=gzip, 2=zlib envelope (raw-deflate fallbacks after
     * stripping the two leading zlib bytes / trying the whole buffer), 3=stored.
     * Anything else (LZ4, external chunk flag, corruption) returns null -> fog.
     *
     * Every inflate runs BOUNDED ({@see self::MAX_INFLATED_CHUNK_BYTES}).
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
            // Inflate functions throw on corrupt streams depending on build; fall through.
        }

        return null;
    }

    /** Decompression-bomb ceiling for ONE chunk record. */
    private const MAX_INFLATED_CHUNK_BYTES = 16_777_216;

    /**
     * Streaming inflate with an output-size ceiling enforced WHILE feeding input.
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
    /*  Selective big-endian NBT                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Decodes the chunk-root compound (tag id 10 + length-prefixed root name + body),
     * keeping only {@see self::ROOT_KEEP} keys.
     */
    private function decodeChunkNbt(string $binary): ?array
    {
        $len = strlen($binary);

        if ($len < 3 || ord($binary[0]) !== 10) {
            return null;
        }

        $pos = 3 + self::u16($binary, 1);

        if ($pos > $len) {
            return null;
        }

        [$root] = $this->readCompound($binary, $pos, 1, self::ROOT_KEEP, true);

        return $root;
    }

    /**
     * Reads a compound body starting at $pos (right after its name). Children are read
     * at $childDepth; with $keep only those names are built, the rest is skipped.
     *
     * @param  list<string>|null  $keep
     * @return array{0:array<string, mixed>, 1:int}
     */
    private function readCompound(string $binary, int $pos, int $childDepth, ?array $keep, bool $isRoot = false): array
    {
        $len = strlen($binary);
        $out = [];

        while ($pos < $len) {
            $tag = ord($binary[$pos++]);

            if ($tag === 0) {
                break;
            }

            if ($tag > 12 || $pos + 2 > $len) {
                return [$out, $len];
            }

            $keyLen = self::u16($binary, $pos);
            $key = substr($binary, $pos + 2, $keyLen);
            $pos += 2 + $keyLen;

            if ($keep !== null && ! in_array($key, $keep, true)) {
                $pos = self::skipPayload($binary, $pos, $tag, $childDepth);

                continue;
            }

            if ($isRoot && $tag === 10 && ($key === 'Level' || $key === 'Data')) {
                [$out[$key], $pos] = $this->readCompound($binary, $pos, $childDepth + 1, self::LEVEL_KEEP);
            } elseif ($tag === 9 && ($key === 'sections' || $key === 'Sections')) {
                [$out[$key], $pos] = $this->readSectionList($binary, $pos, $childDepth);
            } else {
                [$out[$key], $pos] = $this->readTagPayload($binary, $pos, $tag, $childDepth);
            }
        }

        return [$out, min($len, $pos)];
    }

    /**
     * Section list: compounds read with {@see self::SECTION_KEEP}.
     *
     * @return array{0:list<array<string, mixed>>, 1:int}
     */
    private function readSectionList(string $binary, int $pos, int $depth): array
    {
        $len = strlen($binary);

        if ($pos + 5 > $len) {
            return [[], $len];
        }

        $elementTag = ord($binary[$pos]);
        $count = self::i32($binary, $pos + 1);
        $cursor = $pos + 5;

        if ($count <= 0 || $elementTag === 0) {
            return [[], $cursor];
        }

        if ($elementTag !== 10) {
            return [[], self::skipPayload($binary, $pos, 9, $depth)];
        }

        $items = [];

        for ($i = 0; $i < $count && $cursor < $len; $i++) {
            if ($i >= self::MAX_SECTIONS) {
                $cursor = self::skipPayload($binary, $cursor, 10, $depth + 1);

                continue;
            }

            [$items[], $cursor] = $this->readCompound($binary, $cursor, $depth + 2, self::SECTION_KEEP);
        }

        return [$items, min($len, $cursor)];
    }

    /**
     * Reads ONE wanted tag payload; returns [value, nextPosition]. Beyond
     * {@see self::MAX_NBT_DEPTH} the value is skipped (correct end position, no data).
     *
     * @return array{0:mixed, 1:int}
     */
    private function readTagPayload(string $binary, int $pos, int $tagId, int $depth): array
    {
        $len = strlen($binary);

        if ($pos > $len) {
            return [null, $len];
        }

        if ($depth > self::MAX_NBT_DEPTH) {
            return [null, self::skipPayload($binary, $pos, $tagId, 0)];
        }

        switch ($tagId) {
            case 1: // Byte (Java signed byte -> PHP signed int)
                if ($pos + 1 > $len) {
                    return [0, $len];
                }

                $raw = ord($binary[$pos]);

                return [$raw > 127 ? $raw - 256 : $raw, $pos + 1];

            case 2: // Short
                if ($pos + 2 > $len) {
                    return [0, $len];
                }

                $raw = self::u16($binary, $pos);

                return [$raw > 32767 ? $raw - 65536 : $raw, $pos + 2];

            case 3: // Int
                if ($pos + 4 > $len) {
                    return [0, $len];
                }

                return [self::i32($binary, $pos), $pos + 4];

            case 4: // Long (two's complement identical to the wire form)
                if ($pos + 8 > $len) {
                    return [0, $len];
                }

                return [(int) (unpack('J', substr($binary, $pos, 8))[1] ?? 0), $pos + 8];

            case 5: // Float (format G = big-endian float32)
                if ($pos + 4 > $len) {
                    return [0.0, $len];
                }

                return [(float) (unpack('G', substr($binary, $pos, 4))[1] ?? 0.0), $pos + 4];

            case 6: // Double
                if ($pos + 8 > $len) {
                    return [0.0, $len];
                }

                return [(float) (unpack('E', substr($binary, $pos, 8))[1] ?? 0.0), $pos + 8];

            case 7: // ByteArray
                if ($pos + 4 > $len) {
                    return ['', $len];
                }

                $size = max(0, self::i32($binary, $pos));
                $bodyStart = $pos + 4;
                $size = min($size, $len - $bodyStart);

                return [substr($binary, $bodyStart, $size), $bodyStart + $size];

            case 8: // String (modified UTF-8, read verbatim; renderer compares ASCII ids only)
                if ($pos + 2 > $len) {
                    return ['', $len];
                }

                $strLen = self::u16($binary, $pos);
                $bodyStart = $pos + 2;

                return [substr($binary, $bodyStart, min($strLen, $len - $bodyStart)), min($len, $bodyStart + $strLen)];

            case 9: // List
                if ($pos + 5 > $len) {
                    return [[], $len];
                }

                $elementTag = ord($binary[$pos]);
                $count = self::i32($binary, $pos + 1);
                $cursor = $pos + 5;

                if ($count <= 0 || $elementTag === 0) {
                    return [[], $cursor];
                }

                if ($elementTag > 12) {
                    return [[], $len];
                }

                $items = [];
                $cap = min($count, 4096); // palettes top out at 4096 entries

                for ($i = 0; $i < $cap && $cursor < $len; $i++) {
                    [$items[], $cursor] = $this->readTagPayload($binary, $cursor, $elementTag, $depth + 1);
                }

                for (; $i < $count && $cursor < $len; $i++) {
                    $cursor = self::skipPayload($binary, $cursor, $elementTag, $depth + 1);
                }

                return [$items, min($len, $cursor)];

            case 10: // Compound
                return $this->readCompound($binary, $pos, $depth + 1, null);

            case 11: // IntArray
                if ($pos + 4 > $len) {
                    return [[], $len];
                }

                $count = max(0, self::i32($binary, $pos));
                $bodyStart = $pos + 4;
                $take = min($count, intdiv(max(0, $len - $bodyStart), 4), 65536);

                $values = $take > 0 ? array_values(unpack('N'.$take, substr($binary, $bodyStart, $take * 4)) ?: []) : [];
                $values = array_map(static fn (int $raw): int => $raw > 2147483647 ? $raw - 4294967296 : $raw, $values);

                return [$values, min($len, $bodyStart + $count * 4)];

            case 12: // LongArray
                if ($pos + 4 > $len) {
                    return [[], $len];
                }

                $count = max(0, self::i32($binary, $pos));
                $bodyStart = $pos + 4;
                $take = min($count, intdiv(max(0, $len - $bodyStart), 8), 262144);

                $values = [];

                if ($take > 0) {
                    $raw = unpack('N'.($take * 2), substr($binary, $bodyStart, $take * 8)) ?: [];

                    for ($i = 1; $i <= $take * 2; $i += 2) {
                        // $hi << 32 wraps to the signed 64-bit value by design.
                        $values[] = (((int) ($raw[$i] ?? 0)) << 32) | ((int) ($raw[$i + 1] ?? 0));
                    }
                }

                return [$values, min($len, $bodyStart + $count * 8)];

            default: // Unknown ids cannot be skipped soundly: end this scope.
                return [null, $len];
        }
    }

    /**
     * Advances over one payload without building values; returns the next position.
     * Lists of fixed-size scalars are skipped arithmetically.
     */
    private static function skipPayload(string $binary, int $pos, int $tagId, int $depth): int
    {
        $len = strlen($binary);

        if ($pos >= $len || $depth > self::MAX_SKIP_DEPTH) {
            return $len;
        }

        switch ($tagId) {
            case 1:
                return min($len, $pos + 1);
            case 2:
                return min($len, $pos + 2);
            case 3:
            case 5:
                return min($len, $pos + 4);
            case 4:
            case 6:
                return min($len, $pos + 8);
            case 7:
            case 11:
            case 12:
                if ($pos + 4 > $len) {
                    return $len;
                }

                $unit = $tagId === 7 ? 1 : ($tagId === 11 ? 4 : 8);

                return min($len, $pos + 4 + max(0, self::i32($binary, $pos)) * $unit);
            case 8:
                if ($pos + 2 > $len) {
                    return $len;
                }

                return min($len, $pos + 2 + self::u16($binary, $pos));
            case 9:
                if ($pos + 5 > $len) {
                    return $len;
                }

                $elementTag = ord($binary[$pos]);
                $count = self::i32($binary, $pos + 1);
                $cursor = $pos + 5;

                if ($count <= 0 || $elementTag === 0) {
                    return $cursor;
                }

                if ($elementTag > 12) {
                    return $len;
                }

                $fixed = [1 => 1, 2 => 2, 3 => 4, 4 => 8, 5 => 4, 6 => 8][$elementTag] ?? null;

                if ($fixed !== null) {
                    return min($len, $cursor + $count * $fixed);
                }

                for ($i = 0; $i < $count && $cursor < $len; $i++) {
                    $cursor = self::skipPayload($binary, $cursor, $elementTag, $depth + 1);
                }

                return min($len, $cursor);
            case 10:
                $cursor = $pos;

                while ($cursor < $len) {
                    $inner = ord($binary[$cursor++]);

                    if ($inner === 0) {
                        return $cursor;
                    }

                    if ($inner > 12 || $cursor + 2 > $len) {
                        return $len;
                    }

                    $cursor += 2 + self::u16($binary, $cursor);
                    $cursor = self::skipPayload($binary, $cursor, $inner, $depth + 1);
                }

                return $len;
            default:
                return $len;
        }
    }

    private static function u16(string $binary, int $pos): int
    {
        return (int) (unpack('n', substr($binary, $pos, 2))[1] ?? 0);
    }

    private static function i32(string $binary, int $pos): int
    {
        $raw = (int) (unpack('N', substr($binary, $pos, 4))[1] ?? 0);

        return $raw > 2147483647 ? $raw - 4294967296 : $raw;
    }

    /* ------------------------------------------------------------------ */
    /*  Sections                                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Normalizes both section layouts into rows sorted by Y descending:
     * [{y:int, palette_names:list<string>, state_words:list<int>, uniform:bool}]
     * where state_words flattens every packed long into its two 32-bit halves
     * ([lo, hi, lo, hi, ...]) so bit windows never need 64-bit shifting, and `uniform`
     * marks a single-entry palette without data (the whole section is palette[0]).
     *
     * @return list<array{y:int, palette_names:list<string>, state_words:list<int>, uniform:bool}>
     */
    private function sectionsOf(array $root): array
    {
        $candidates = [
            $root['sections'] ?? null,
            $root['Sections'] ?? null,
            $root['Level']['Sections'] ?? null,
            $root['Level']['sections'] ?? null,
            $root['Data']['Sections'] ?? null,
            $root['Data']['sections'] ?? null,
        ];

        foreach ($candidates as $raw) {
            if (! is_array($raw) || $raw === []) {
                continue;
            }

            $rows = [];

            foreach ($raw as $section) {
                if (! is_array($section) || ! is_int($section['Y'] ?? null)) {
                    continue;
                }

                $blockStates = is_array($section['block_states'] ?? null) ? $section['block_states'] : [];
                $palette = $section['Palette'] ?? ($blockStates['palette'] ?? null);
                $data = $section['BlockStates'] ?? ($blockStates['data'] ?? null);

                if (! is_array($palette) || $palette === []) {
                    continue;
                }

                $names = [];

                foreach ($palette as $block) {
                    $names[] = $this->normalizeBlockName(self::paletteEntryId($block));
                }

                $words = $this->flattenBitStates($data);

                if ($words === [] && count($names) !== 1) {
                    continue; // multi-entry palette without data is malformed
                }

                $rows[] = [
                    'y' => $section['Y'],
                    'palette_names' => $names,
                    'state_words' => $words,
                    'uniform' => $words === [],
                ];
            }

            usort($rows, static fn (array $a, array $b): int => $b['y'] <=> $a['y']);

            return $rows;
        }

        return [];
    }

    /**
     * Block id of one block-state palette entry, whatever the encoding:
     *
     * - up to 1.21.x: compound `{Name: "minecraft:stone", Properties: {…}}`;
     * - 26.1+: a plain string `"minecraft:stone"` for a state without stored properties,
     *   `{id: "minecraft:water", properties: {…}}` otherwise. A palette mixing both is a list
     *   of compounds in which the plain entries are wrapped as `{"": "minecraft:stone"}`
     *   (NBT lists are homogeneous: vanilla's heterogeneous-list encoding).
     *
     * Verified on a Paper 26.3 world (DataVersion 5023): string-only palettes are the norm,
     * mixed ones use exactly the two compound shapes above.
     */
    private static function paletteEntryId(mixed $entry): string
    {
        if (is_string($entry)) {
            return $entry;
        }

        if (! is_array($entry)) {
            return '';
        }

        foreach (['Name', 'id', ''] as $key) {
            if (is_string($entry[$key] ?? null)) {
                return $entry[$key];
            }
        }

        return '';
    }

    /**
     * Normalizes `minecraft:oak_leaves` -> `oak_leaves` and lowercases anything
     * coming from non-vanilla namespaces.
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
     * Turns the packed longs into the flat 32-bit word array the bit-unpacker consumes.
     * Java long bit 0 lives in the LOW half, so each long contributes [lo, hi]; the
     * arithmetic `>> 32` of a negative long is masked back to 32 bits.
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
     * Extracts one LSB-first bit window from the flattened word stream; windows may
     * cross word (and, in the spanning layout, long) boundaries.
     */
    private static function readWindow(array $words, int $startBit, int $width): int
    {
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
     * Paints the coarse-sampled column grid of one chunk onto the buffer. Each output
     * pixel takes the topmost visible block at its north-west sample anchor.
     */
    private function paintChunkColumns(
        array $sections,
        int $cx,
        int $cz,
        int $coarse,
        int $yTopCap,
        bool $spanning,
        string &$buffer,
        int $side,
    ): void {
        // Pre-resolve kind codes and colors once per section.
        foreach ($sections as &$section) {
            $kinds = [];
            $colors = [];

            foreach ($section['palette_names'] as $idx => $name) {
                $kinds[$idx] = self::classify($name);
                $colors[$idx] = $this->rgbFor($name);
            }

            $section['kinds'] = $kinds;
            $section['color_rgb'] = $colors;
            $section['bits'] = max(4, (int) ceil(log(max(count($section['palette_names']), 1), 2)));
        }

        unset($section);

        $step = max(1, $coarse);

        for ($localZ = 0; $localZ < self::BLOCKS_PER_CHUNK; $localZ += $step) {
            for ($localX = 0; $localX < self::BLOCKS_PER_CHUNK; $localX += $step) {
                $columnRgb = $this->columnColor($sections, $localX, $localZ, $yTopCap, $spanning);

                if ($columnRgb === null) {
                    continue; // leave fog underneath.
                }

                $px = intdiv($cx * self::BLOCKS_PER_CHUNK + $localX, $step);
                $pz = intdiv($cz * self::BLOCKS_PER_CHUNK + $localZ, $step);

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
        if ($name === '' || $name === 'air' || $name === 'void_air' || $name === 'cave_air') {
            return 0;
        }

        if (str_contains($name, 'water') || in_array($name, ['bubble_column', 'seagrass', 'tall_seagrass', 'sea_grass', 'kelp', 'kelp_plant'], true)) {
            return 2;
        }

        if (str_contains($name, 'glass') || in_array($name, self::PASS_THROUGH, true)) {
            return 3;
        }

        foreach (self::PASS_THROUGH_SUFFIXES as $suffix) {
            if (str_ends_with($name, $suffix)) {
                return 3;
            }
        }

        return 1;
    }

    /**
     * Walks one column downwards until a terminating block appears. Returns the final
     * RGB triplet or null (leave-fog contract). The first water block stores the tint;
     * when ground shows up beneath it, WATER_BLEND_FACTOR of the tint is composited
     * over the ground tone; an all-water column paints deep water.
     *
     * @return array{0:int, 1:int, 2:int}|null
     */
    private function columnColor(array $sections, int $localX, int $localZ, int $yTopCap, bool $spanning): ?array
    {
        $waterAbove = null;
        $encountered = false; // did we meet ANY informative (non-air) block?

        foreach ($sections as $section) {
            $paletteSize = count($section['palette_names']);

            if ($paletteSize === 0) {
                continue;
            }

            $kinds = $section['kinds'];

            // Whole-section single block: air sections cost nothing.
            if ($section['uniform'] && ($kinds[0] ?? 0) === 0) {
                continue;
            }

            $baseY = $section['y'] * 16;
            $bitSize = $section['bits'];
            $entriesPerLong = intdiv(64, $bitSize);

            for ($yLocal = 15; $yLocal >= 0; $yLocal--) {
                if ($baseY + $yLocal > $yTopCap) {
                    continue;
                }

                if ($section['uniform']) {
                    $entry = 0;
                } else {
                    // Section block order: Y-major, then Z, then X.
                    $index = ($yLocal * 256) + ($localZ * 16) + $localX;
                    $startBit = $spanning
                        ? $index * $bitSize
                        : intdiv($index, $entriesPerLong) * 64 + ($index % $entriesPerLong) * $bitSize;
                    $entry = self::readWindow($section['state_words'], $startBit, $bitSize);
                }

                if ($entry >= $paletteSize) {
                    continue;
                }

                $kind = $kinds[$entry] ?? 1;

                if ($kind === 0 || $kind === 3) {
                    continue; // air, glass roofs and small decorations: look beneath.
                }

                $encountered = true;
                $rgb = $section['color_rgb'][$entry] ?? self::hexToRgb(self::UNKNOWN_BLOCK_COLOR);

                if ($kind === 2) {
                    $waterAbove ??= $rgb;

                    continue; // carry on below for the underwater ground tone.
                }

                return $waterAbove === null ? $rgb : self::blendWater($waterAbove, $rgb);
            }
        }

        if (! $encountered) {
            return null; // nothing informative in this column -> keep fog
        }

        return $waterAbove === null
            ? self::hexToRgb(self::UNKNOWN_BLOCK_COLOR)
            : self::blendWater($waterAbove, self::hexToRgb(self::DEEP_WATER_HEX));
    }

    /**
     * Color of one block id: exact table hit, then shape-stripped material fallbacks
     * (oak_slab -> oak_planks, stone_brick_stairs -> stone_bricks…), else neutral gray.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function rgbFor(string $name): array
    {
        if (isset($this->rgbCache[$name])) {
            return $this->rgbCache[$name];
        }

        $known = $this->colors();
        $hex = $known[$name] ?? null;

        if ($hex === null) {
            foreach (self::SHAPE_SUFFIXES as $suffix) {
                if (! str_ends_with($name, $suffix)) {
                    continue;
                }

                $base = substr($name, 0, -strlen($suffix));

                foreach ([$base, $base.'_planks', $base.'s', $base.'_block'] as $candidate) {
                    if (isset($known[$candidate])) {
                        $hex = $known[$candidate];

                        break 2;
                    }
                }
            }
        }

        if (! is_string($hex) || preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = $name === '' ? self::FOG_COLOR_HEX : self::UNKNOWN_BLOCK_COLOR;
        }

        return $this->rgbCache[$name] = self::hexToRgb($hex);
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
                    $decoded = json_decode((string) file_get_contents($path), true, 8, JSON_THROW_ON_ERROR) ?? [];
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

    /** @return array{0:int,1:int,2:int} */
    private static function hexToRgb(string $hex): array
    {
        $value = (int) hexdec(substr(trim($hex, '#'), 0, 6));

        return [($value >> 16) & 0xFF, ($value >> 8) & 0xFF, $value & 0xFF];
    }

    /**
     * Composite blend: `factor` amount of the water tint layered over the ground tone.
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
