<?php

declare(strict_types=1);

namespace McToolkit\Services\Maps;

use App\Models\Server;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Worlds\WorldsService;
use McToolkit\Support\CacheKeys;

/**
 * Orchestration layer between the HTTP tile endpoint / Filament map page and
 * {@see RegionParser}.
 *
 * Responsibilities:
 * - resolve where one dimension of one world keeps its `.mca` files: overworld
 *   `<dir>/region`; nether `<dir>/DIM-1/region` (vanilla nested AND Paper's
 *   `<level>_nether/DIM-1/region`); end `<dir>/DIM1/region` — with a plain
 *   `<dir>/region` fallback for forks that flatten split worlds;
 * - enumerate the `r.<rx>.<rz>.mca` files there, nearest-to-spawn first, capped at
 *   {@see self::MAX_REGIONS_PER_SCAN} with a truncation flag;
 * - render ONE top-down region tile into packed RGBA + base64 JSON-ready payload,
 *   cached server-side for config('minecraft-toolkit.map.tile_cache_ttl', 900)s so
 *   pan/zoom hammers stay off the daemon;
 * - hand back exact geometry facts (origin block coords, fog color) so the canvas JS
 *   places tiles without guesswork.
 *
 * World folder names are taken verbatim (spaces and any other character included);
 * only names that could leave the server root (`/`, `\`, `.`, `..`, NUL) are refused,
 * see {@see WorldsService::isSafeDirectoryName()}.
 *
 * Cache model: tile keys embed the resolved region folder, the region file's wings
 * mtime and a per-folder GENERATION integer. A changed region file therefore renders
 * fresh pixels by itself; {@see self::invalidate()} (Redraw) bumps the generation and
 * drops the local `.mca` mirror. Plain cache()->get()/put() only, no wildcard deletes.
 *
 * Memory honesty: the largest legal raster costs `side^2 * 4` raw bytes plus ~4/3
 * base64 expansion (<= ~1.4 MiB at side 512). The upstream `.mca` fetch itself is capped
 * by config('minecraft-toolkit.limits.region_fetch_max_bytes') inside RegionParser.
 */
final class MapTileService
{
    /** Region tiles are always square over REGION_BLOCKS_AXIS blocks (32 chunks * 16). */
    public const REGION_BLOCKS_AXIS = RegionParser::BLOCKS_PER_REGION_AXIS;

    public const CHUNKS_PER_REGION_AXIS = RegionParser::CHUNKS_PER_REGION_AXIS;

    /** Dimensions the map can draw. */
    public const DIMENSIONS = ['overworld', 'nether', 'end'];

    /**
     * Accepted downscale factors: how many world blocks one output pixel spans.
     * Anything else falls back to {@see self::DEFAULT_COARSE}.
     */
    public const COARSE_OPTIONS = [1, 2, 4, 8];

    /** First-choice rendering fidelity (256 px wide tile). */
    public const DEFAULT_COARSE = 2;

    /** Never allocate above this output width, whatever a caller asks for. */
    public const MAX_RENDER_SIDE = 512;

    /** Regions handed to the canvas per world; the nearest to spawn win. */
    public const MAX_REGIONS_PER_SCAN = 64;

    /** Vanilla world border radius in regions (30M blocks / 512). */
    public const MAX_REGION_COORDINATE = 58_594;

    public const DEFAULT_Y_RANGE_TOP = 320;

    private const FOG_COLOR_HEX = RegionParser::FOG_COLOR_HEX;

    /** Region folder candidates per dimension, relative to the world folder. */
    private const REGION_CANDIDATES = [
        'overworld' => ['region'],
        'nether' => ['DIM-1/region', 'region'],
        'end' => ['DIM1/region', 'region'],
    ];

    /** How long a resolved region folder is remembered. */
    private const REGION_DIR_TTL_SECONDS = 600;

    public function __construct(
        private readonly FileBridge $bridge,
        private readonly RegionParser $parser,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Region enumeration                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Lists the known regions of one world dimension, nearest-to-spawn first.
     *
     * @return list<array{rx:int, rz:int, mtime:int, size:int}>
     */
    public function availableRegions(Server $server, string $worldDir, string $dimension = 'overworld', ?int $spawnX = null, ?int $spawnZ = null): array
    {
        return $this->availableRegionsWithMeta($server, $worldDir, $dimension, $spawnX, $spawnZ)['regions'];
    }

    /**
     * Same enumeration plus the truncation flag the UI needs to explain giant worlds.
     *
     * @return array{regions:list<array{rx:int, rz:int, mtime:int, size:int}>, truncated:bool, total:int}
     */
    public function availableRegionsWithMeta(Server $server, string $worldDir, string $dimension = 'overworld', ?int $spawnX = null, ?int $spawnZ = null): array
    {
        $empty = ['regions' => [], 'truncated' => false, 'total' => 0];

        if (! WorldsService::isSafeDirectoryName($worldDir) || ! isset(self::REGION_CANDIDATES[$dimension])) {
            return $empty;
        }

        $entries = null;

        foreach (self::REGION_CANDIDATES[$dimension] as $suffix) {
            $directory = $worldDir.'/'.$suffix;

            try {
                $entries = $this->bridge->ls($server, '/'.$directory);
            } catch (\Throwable) {
                continue;
            }

            $this->rememberRegionDirectory($server, $worldDir, $dimension, $directory);

            break;
        }

        if ($entries === null) {
            return $empty;
        }

        // Region of the spawn block (floor division: block -1 lives in region -1).
        $originX = (int) floor(($spawnX ?? 0) / self::REGION_BLOCKS_AXIS);
        $originZ = (int) floor(($spawnZ ?? 0) / self::REGION_BLOCKS_AXIS);

        $regions = [];

        foreach ($entries as $entry) {
            $name = strval($entry['name'] ?? '');

            if (($entry['file'] ?? false) !== true || preg_match('/^r\.(-?\d+)\.(-?\d+)\.mca$/', $name, $m) !== 1) {
                continue;
            }

            $modified = isset($entry['modified']) ? strtotime((string) $entry['modified']) : false;

            $regions[] = [
                'rx' => (int) $m[1],
                'rz' => (int) $m[2],
                'mtime' => $modified === false ? 0 : max(0, $modified),
                'size' => max(0, (int) ($entry['size'] ?? 0)),
            ];
        }

        // Center-out around the spawn region, then a stable coordinate order.
        usort($regions, static function (array $a, array $b) use ($originX, $originZ): int {
            $da = ($a['rx'] - $originX) ** 2 + ($a['rz'] - $originZ) ** 2;
            $db = ($b['rx'] - $originX) ** 2 + ($b['rz'] - $originZ) ** 2;

            return [$da, $a['rx'], $a['rz']] <=> [$db, $b['rx'], $b['rz']];
        });

        $total = count($regions);

        return [
            'regions' => array_slice($regions, 0, self::MAX_REGIONS_PER_SCAN),
            'truncated' => $total > self::MAX_REGIONS_PER_SCAN,
            'total' => $total,
        ];
    }

    /**
     * Server-relative region folder of one world dimension (e.g. `world_nether/DIM-1/region`),
     * or null when none of the candidates exists.
     */
    public function regionDirectory(Server $server, string $worldDir, string $dimension): ?string
    {
        if (! WorldsService::isSafeDirectoryName($worldDir) || ! isset(self::REGION_CANDIDATES[$dimension])) {
            return null;
        }

        try {
            $cached = cache()->get($this->regionDirectoryKey($server, $worldDir, $dimension));
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        foreach (self::REGION_CANDIDATES[$dimension] as $suffix) {
            $directory = $worldDir.'/'.$suffix;

            try {
                $this->bridge->ls($server, '/'.$directory);
            } catch (\Throwable) {
                continue;
            }

            $this->rememberRegionDirectory($server, $worldDir, $dimension, $directory);

            return $directory;
        }

        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Invalidation ("Redraw")                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Drops the local `.mca` mirror of one world dimension and moves its render
     * generation forward so every tile re-renders from freshly downloaded data.
     * Best-effort: failures leave tiles to expire on TTL.
     */
    public function invalidate(Server $server, string $worldDir, string $dimension): void
    {
        try {
            cache()->forget($this->regionDirectoryKey($server, $worldDir, $dimension));
        } catch (\Throwable) {
            // stale folder resolution simply ages out
        }

        $regionDir = $this->regionDirectory($server, $worldDir, $dimension);

        if ($regionDir === null) {
            return;
        }

        $this->parser->purgeMirror($server, $regionDir);

        try {
            $key = $this->generationKey($server, $regionDir);

            cache()->put($key, ((int) cache()->get($key, 1)) + 1, now()->addDay());
        } catch (\Throwable) {
            // Truly best-effort: old tiles keep aging out.
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Rendering                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Renders one `.mca` region tile.
     *
     * @param  int  $mtime      wings mtime of the region file as listed for the page (0 = unknown)
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
        string $dimension,
        int $regionX,
        int $regionZ,
        int $mtime = 0,
        int $yRangeTop = self::DEFAULT_Y_RANGE_TOP,
        int $coarse = self::DEFAULT_COARSE,
    ): ?array {
        if (abs($regionX) > self::MAX_REGION_COORDINATE || abs($regionZ) > self::MAX_REGION_COORDINATE) {
            return null;
        }

        $regionDir = $this->regionDirectory($server, $worldDir, $dimension);

        if ($regionDir === null) {
            return null;
        }

        [$coarseValidated] = $this->geometryFor($coarse);
        $mtime = max(0, $mtime);

        $key = CacheKeys::ns('map.tile.'.sha1(implode('|', [
            $regionDir,
            $this->generation($server, $regionDir),
            $regionX,
            $regionZ,
            $mtime,
            $yRangeTop,
            $coarseValidated,
        ])), $server);

        try {
            $cached = cache()->get($key);
        } catch (\Throwable) {
            $cached = null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raster = $this->parser->rasterize($server, $regionDir, $regionX, $regionZ, $coarseValidated, $yRangeTop, $mtime > 0 ? $mtime : null);
        } catch (\Throwable $exception) {
            report($exception);

            return null;
        }

        if ($raster === null || ! isset($raster['rgb'], $raster['side'])) {
            return null;
        }

        $side = (int) $raster['side'];
        $pixels = base64_encode(self::attachAlphaChannel($raster['rgb'], $side * $side));

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

    private function rememberRegionDirectory(Server $server, string $worldDir, string $dimension, string $directory): void
    {
        try {
            cache()->put($this->regionDirectoryKey($server, $worldDir, $dimension), $directory, now()->addSeconds(self::REGION_DIR_TTL_SECONDS));
        } catch (\Throwable) {
            // resolution is simply repeated next time
        }
    }

    /** Raw names are hashed: CacheKeys strips/lowercases characters real folder names may use. */
    private function regionDirectoryKey(Server $server, string $worldDir, string $dimension): string
    {
        return CacheKeys::ns('map.regiondir.'.sha1($worldDir.'|'.$dimension), $server);
    }

    private function generationKey(Server $server, string $regionDir): string
    {
        return CacheKeys::ns('map.gen.'.sha1($regionDir), $server);
    }

    /** Current monotonically increasing render generation for one region folder. */
    private function generation(Server $server, string $regionDir): int
    {
        try {
            return max(1, (int) cache()->get($this->generationKey($server, $regionDir), 1));
        } catch (\Throwable) {
            return 1;
        }
    }

    /**
     * Validates the downscale factor and derives the raster side length
     * (side = REGION_BLOCKS_AXIS / coarse, guarded by MAX_RENDER_SIDE).
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
        $needed = $expectedPixels * 4;

        $usable = strlen($rgbBuffer) - (strlen($rgbBuffer) % 3);
        $rgba = $usable > 0 ? implode($opaque, str_split(substr($rgbBuffer, 0, $usable), 3)).$opaque : '';

        if (strlen($rgba) < $needed) {
            $rgba .= str_repeat($fogPixel, intdiv($needed - strlen($rgba), 4));
        }

        return substr($rgba, 0, $needed);
    }

    private function tileTtl(): int
    {
        return (int) (config('minecraft-toolkit.map.tile_cache_ttl', 900));
    }
}
