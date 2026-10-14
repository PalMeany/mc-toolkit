<?php

declare(strict_types=1);

namespace McToolkit\Services\Maps;

use App\Models\Server;
use McToolkit\Services\FileBridge;
use McToolkit\Support\CacheKeys;

/**
 * Orchestration layer between the HTTP tile endpoint / Filament map page and
 * {@see RegionParser}.
 *
 * Responsibilities:
 * - enumerate which `.mca` region files exist for one world directory
 *   (`r.<rx>.<rz>.mca` naming), capped at {@see self::MAX_REGIONS_PER_SCAN} with a
 *   truncation flag so gigantic creative worlds degrade predictably;
 * - render ONE top-down region tile into packed RGBA + base64 JSON-ready payload,
 *   cached server-side for config('minecraft-toolkit.map.tile_cache_ttl', 900)s so
 *   pan/zoom hammers stay off the daemon;
 * - hand back exact geometry facts (origin block coords, sanity span, fog color)
 *   so the canvas JS places tiles without guesswork.
 *
 * Cache invalidation model ("Redraw"):
 * every rendered payload's key embeds a per-server/world GENERATION integer kept
 * in its own cache entry. {@see self::bumpGeneration()} increments it; stale tiles
 * under old generations simply age out through the TTL while every future request
 * re-renders against freshly downloaded `.mca` mirrors. No driver-specific wildcard
 * deletes are relied upon - plain cache()->get()/put() only, per guide §7 guards.
 *
 * Memory honesty: the largest legal raster costs `side^2 * 4` raw bytes plus ~4/3
 * base64 expansion. With {@see self::COARSE_OPTIONS} mapping 512-block regions onto
 * widths of 512/256/128/64 px the worst case stays ~1.0 MiB raw / ~1.37 MiB
 * encoded - comfortably below every cache driver limit and explicitly guarded by
 * {@see self::MAX_RENDER_SIDE}. The upstream `.mca` fetch itself is capped by
 * config('minecraft-toolkit.limits.region_fetch_max_bytes') inside RegionParser.
 */
final class MapTileService
{
    /** Region tiles are always square over REGION_BLOCKS_AXIS blocks (32 chunks * 16). */
    public const REGION_BLOCKS_AXIS = RegionParser::BLOCKS_PER_REGION_AXIS;

    public const CHUNKS_PER_REGION_AXIS = RegionParser::CHUNKS_PER_REGION_AXIS;

    /**
     * Accepted downscale factors: how many world blocks one output pixel spans.
     * Anything else falls back to {@see self::DEFAULT_COARSE}.
     */
    public const COARSE_OPTIONS = [1, 2, 4, 8];

    /** First-choice rendering fidelity (256 px wide tile, ~85 KiB encoded). */
    public const DEFAULT_COARSE = 2;

    /** Never allocate above this output width, whatever a caller asks for. */
    public const MAX_RENDER_SIDE = 512;

    /** Region-directory scans stop here; giants report truncated=true. */
    public const MAX_REGIONS_PER_SCAN = 64;

    private const FOG_COLOR_HEX = RegionParser::FOG_COLOR_HEX;

    private const DEFAULT_Y_RANGE_TOP = 320;

    public function __construct(
        private readonly FileBridge $bridge,
        private readonly RegionParser $parser,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Region enumeration                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Lists the known regions of one world directory, nearest-origin-first.
     *
     * @return list<array{rx:int, rz:int, mtime:?int, size:int}>
     */
    public function availableRegions(Server $server, string $worldDir): array
    {
        return $this->scanRegions($server, $worldDir)['regions'];
    }

    /**
     * Same enumeration plus the truncation flag the UI needs to explain giant worlds.
     *
     * @return array{regions:list<array{rx:int, rz:int, mtime:?int, size:int}>, truncated:bool}
     */
    public function availableRegionsWithMeta(Server $server, string $worldDir): array
    {
        return $this->scanRegions($server, $worldDir);
    }

    /**
     * @return array{regions:list<array{rx:int, rz:int, mtime:?int, size:int}>, truncated:bool}
     */
    private function scanRegions(Server $server, string $worldDir): array
    {
        $directory = rtrim($this->safeSegment($worldDir), '/').'/region';

        try {
            $entries = $this->bridge->ls($server, $directory);
        } catch (\Throwable) {
            return ['regions' => [], 'truncated' => false];
        }

        $regions = [];

        foreach ($entries as $entry) {
            $name = strval($entry['name'] ?? '');

            if (($entry['file'] ?? false) !== true || preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/i', $name, $m) !== 1) {
                continue;
            }

            $regions[] = [
                'rx' => (int) $m[1],
                'rz' => (int) $m[2],
                // wings exposes `modified` as an ISO-ish string when present; coerce defensively.
                'mtime' => isset($entry['modified']) ? (int) strtotime((string) $entry['modified']) : null,
                'size' => (int) ($entry['size'] ?? 0),
            ];

            if (count($regions) >= self::MAX_REGIONS_PER_SCAN) {
                break;
            }
        }

        // Deterministic ordering lets the canvas scheduler's BFS center-out queue
        // stay stable even before its own distance sort runs.
        usort($regions, static fn (array $a, array $b): int => [$a['rx'], $a['rz']] <=> [$b['rx'], $b['rz']]);

        return [
            'regions' => $regions,
            'truncated' => count($regions) >= self::MAX_REGIONS_PER_SCAN,
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Render generations                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Invalidates all currently rendered tiles for one server/world pair by moving
     * the cache-key generation forward. Called by the Redraw control right after
     * the LOCAL `.mca` mirror was cleared, so subsequent requests re-parse fresh
     * wing data instead of serving pixels that were computed pre-refresh.
     *
     * Best-effort by design: a generation write failure cannot corrupt anything,
     * tiles simply expire on TTL.
     */
    public function bumpGeneration(Server $server, string $worldDir): void
    {
        try {
            $key = CacheKeys::ns('map.gen.'.$this->safeSegment($worldDir), $server);

            cache()->put($key, ((int) cache()->get($key, 1)) + 1, now()->addDay());
        } catch (\Throwable) {
            // Truly best-effort: nothing to report loudly here, old tiles keep aging out.
        }
    }

    /**
     * Current monotonically increasing render generation for one server/world pair.
     */
    private function generation(Server $server, string $worldDir): int
    {
        try {
            return max(1, (int) cache()->get(CacheKeys::ns('map.gen.'.$this->safeSegment($worldDir), $server), 1));
        } catch (\Throwable) {
            return 1;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Rendering                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Renders one `.mca` region tile.
     *
     * Signature alignment note (documented mismatch resolution):
     * - CONTRACTS.md freezes `renderRegion(server, worldDir, rx, rz, yRangeTop=320)`;
     * - the pre-existing MapTileController invokes exactly that fifth argument
     *   positionally (`DEFAULT_Y_RANGE_TOP = 320`);
     * - this wave's design brief additionally demands a coarse downscale factor
     *   whose first choice yields 256 px wide tiles.
     * Resolution: BOTH coexist - the frozen parameter order is untouched and the
     * coarse knob is appended AFTER it (defaulting to {@see self::DEFAULT_COARSE}),
     * so the live controller keeps functioning unchanged while richer callers may
     * tune fidelity explicitly.
     *
     * @param  int  $yRangeTop  highest world Y scanned while walking columns down
     * @param  int  $coarse     one of COARSE_OPTIONS; how many blocks each output pixel spans
     * @return array{
     *     width:int, height:int, pixels:string(base64 RGBA, always opaque),
     *     originX:int, originZ:int, chunkSpanForSanity:int, blocksPerPixel:int,
     *     present_chunks:int, unparsed_chunks:int, fog:string,
     * }|null  null whenever the region cannot be obtained/rendered (client paints fog)
     */
    public function renderRegion(
        Server $server,
        string $worldDir,
        int $regionX,
        int $regionZ,
        int $yRangeTop = self::DEFAULT_Y_RANGE_TOP,
        int $coarse = self::DEFAULT_COARSE,
    ): ?array {
        [$coarseValidated, $side] = $this->geometryFor($coarse);

        $key = CacheKeys::ns(sprintf(
            'map.tile.%s.%d.%d.%d.%d.%d',
            $this->safeSegment($worldDir),
            $this->generation($server, $worldDir),
            $regionX,
            $regionZ,
            $yRangeTop,
            $coarseValidated,
        ), $server);

        try {
            $cached = cache()->get($key);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raster = $this->parser->rasterize($server, $worldDir, $regionX, $regionZ, $coarseValidated, $yRangeTop);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        if ($raster === null || ! isset($raster['rgb'], $raster['side'])) {
            return null;
        }

        $side = (int) $raster['side'];
        $pixels = base64_encode(self::attachAlphaChannel($raster['rgb'], $side * $side));

        if ($pixels === '') {
            return null;
        }

        $tile = [
            'width' => $side,
            'height' => $side,
            'pixels' => $pixels,
            'originX' => $regionX * self::REGION_BLOCKS_AXIS,
            'originZ' => $regionZ * self::REGION_BLOCKS_AXIS,
            // Sanity marker letting clients assert identical tile geometry everywhere.
            'chunkSpanForSanity' => self::CHUNKS_PER_REGION_AXIS,
            'blocksPerPixel' => $coarseValidated,
            'present_chunks' => (int) ($raster['present_chunks'] ?? 0),
            'unparsed_chunks' => max(0, (int) ($raster['total_chunks'] ?? 0) - (int) ($raster['present_chunks'] ?? 0)),
            'fog' => self::FOG_COLOR_HEX,
        ];

        try {
            cache()->put($key, $tile, now()->addSeconds($this->tileTtl()));
        } catch (\Throwable) {
            // A failed cache WRITE must never kill an otherwise successful render.
        }

        return $tile;
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Validates the downscale factor and derives the raster side length.
     *
     * Memory-ceiling formula (documented contract):
     *   side = REGION_BLOCKS_AXIS / coarse
     *   rawRGBA bytes = side^2 * 4      (<= ~1.04 MiB at side 512)
     *   encoded bytes ~= raw * 4/3      (<= ~1.38 MiB at side 512)
     * Guarded by MAX_RENDER_SIDE; violators fall back to DEFAULT_COARSE.
     *
     * @return array{0:int, 1:int} [coarse, side]
     */
    private function geometryFor(int $coarse): array
    {
        $coarse = in_array($coarse, self::COARSE_OPTIONS, true) ? $coarse : self::DEFAULT_COARSE;

        $side = intdiv(self::REGION_BLOCKS_AXIS, $coarse);

        if ($side < 1 || $side > self::MAX_RENDER_SIDE) {
            $coarse = self::DEFAULT_COARSE;
        }

        return [$coarse, intdiv(self::REGION_BLOCKS_AXIS, $coarse)];
    }

    /**
     * Appends 255-alpha bytes to an RGB-triplet buffer, producing the RGBA stream
     * encoded into `pixels`. Output length is guaranteed exactly `$expectedPixels*4`
     * even if the underlying buffer came back short (fog fill pads the tail).
     */
    private static function attachAlphaChannel(string $rgbBuffer, int $expectedPixels): string
    {
        $opaque = chr(255);
        $fogPixel = chr(11).chr(14).chr(20).$opaque;

        $rgba = '';

        foreach (str_split($rgbBuffer, 3) as $pixel) {
            $rgba .= (strlen($pixel) === 3 ? $pixel : $fogPixel.$fogPixel.$fogPixel).$opaque;
        }

        $needed = $expectedPixels * 4;

        if (strlen($rgba) < $needed) {
            $rgba .= str_repeat($fogPixel, intdiv($needed - strlen($rgba), 4));
        }

        return substr($rgba, 0, $needed);
    }

    private function safeSegment(string $worldDir): string
    {
        $clean = basename(str_replace('\\', '/', trim($worldDir)));

        return preg_replace('/[^A-Za-z0-9._-]/', '_', $clean) ?: 'world';
    }

    private function tileTtl(): int
    {
        return (int) (config('minecraft-toolkit.map.tile_cache_ttl', 900));
    }
}
