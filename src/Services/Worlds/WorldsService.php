<?php

declare(strict_types=1);

namespace McToolkit\Services\Worlds;

use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Nbt\NbtReader;
use McToolkit\Support\Activity;

/**
 * World-set discovery and lifecycle orchestration for the Worlds page.
 *
 * Detection model — BOTH layout conventions are supported side by side:
 *
 *  a) modern vanilla/Paper: one folder holds `region/` (overworld) plus nested
 *     `DIM-1/…` and `DIM1/…` sub-folders for nether/end;
 *  b) classic Bukkit multi-folder: sibling directories `world`, `world_nether`
 *     (`DIM-1` inside), `world_the_end` are grouped into one logical world set.
 *
 * A directory counts as a world candidate when it directly contains `region/`,
 * or nests DIM-1/DIM1 folders. Suffix pairs (`*_nether`, `*_the_end`) link to the
 * base directory stripped of the suffix whenever that base exists at root level,
 * matching either naming convention the addon demo exposes.
 *
 * Cost guards: every listing goes through {@see FileBridge::safeLs()}, structural
 * probes and size summation draw from capped per-call budgets, and repeated
 * listings reuse an in-call cache.
 *
 * Data enrichment parses `<dir>/level.dat` through {@see NbtReader}; unreadable
 * metadata only blanks the individual fields, never fails the page.
 */
final class WorldsService
{
    /** Beyond this byte estimate rows render with a "~" prefix in the UI. */
    public const SIZE_APPROXIMATE_THRESHOLD = 209_715_200; // >200 MiB

    /** Directory listings allowed to ONE listWorlds() structure-probe pass. */
    private const MAX_PROBE_LISTINGS = 40;

    /** Extra listings pool powering recursive size estimation. */
    public const MAX_SIZE_LISTINGS = 24;

    /** Recursion depth cap for size sums below each world root. */
    private const MAX_SIZE_RECURSION_LEVELS = 2;

    /** Bytes cap handed to FileBridge so corrupted gzip payloads die before gzdecode. */
    private const LEVEL_DAT_TRANSFER_CAP = 16_777_216;

    /** Root folders never probed as worlds (egg tooling conventions). */
    private const NON_WORLD_ROOTS = [
        'plugins', 'mods', 'libraries', 'logs', 'config', 'scripts',
        'backups', 'cache', '.toolkit',
    ];

    /** Suffix -> logical dimension id mapping. */
    private const LINK_SUFFIXES = [
        '_nether' => 'nether',
        '_the_end' => 'end',
    ];

    /** Sibling dirs considered when composing archive/reset sets. */
    private const GROUP_SUFFIXES = ['_nether', '_the_end'];

    public function __construct(
        private readonly FileBridge $bridge,
    ) {}

    /* ------------------------------------------------------------------ */
    /*  Listing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Logical world inventory enriched with level.dat metadata.
     *
     * Rows carry the frozen contract shape plus one additive convenience flag:
     * `approximate` marks byte estimates above {@see self::SIZE_APPROXIMATE_THRESHOLD}
     * so the page can render the "~"-prefix without repeating the policy client-side.
     *
     * @return list<array<string, mixed>>
     *
     * @throws ConnectionException when the node cannot be reached.
     */
    public function listWorlds(Server $server): array
    {
        $rootEntries = $this->bridge->safeLs($server, '/');
        $directories = [];

        foreach ($rootEntries as $entry) {
            if (($entry['directory'] ?? false) === false) {
                continue;
            }

            $name = strval($entry['name']);

            if ($name === '' || in_array(mb_strtolower($name), self::NON_WORLD_ROOTS, true)) {
                continue;
            }

            $directories[] = $name;
        }

        sort($directories);

        $budget = new Budget(self::MAX_PROBE_LISTINGS);
        $levelName = $this->activeLevelName($server);

        $probes = [];

        foreach ($directories as $name) {
            if ($budget->exhausted()) {
                break;
            }

            $probes[$name] = $this->probeDirectory($server, $name, $budget);
        }

        $sizes = new SizeCache($server, $this->bridge);

        return $this->assembleRows($server, $probes, $directories, $levelName, $sizes);
    }

    /**
     * One directory's structural capability flags.
     *
     * @return array{name:string,candidate:bool,has_region:bool,dim_nether:bool,dim_end:bool}
     */
    private function probeDirectory(Server $server, string $name, Budget $budget): array
    {
        $result = [
            'name' => $name,
            'candidate' => false,
            'has_region' => false,
            'dim_nether' => false,
            'dim_end' => false,
        ];

        $entries = $this->probeList($server, $name, $budget);
        $subdirs = [];

        foreach ($entries as $entry) {
            if (($entry['directory'] ?? false) === false) {
                continue;
            }

            $subdirs[strval($entry['name'])] = true;
        }

        if (isset($subdirs['region']) || isset($subdirs['REGION'])) {
            $result['has_region'] = true;
            $result['candidate'] = true;
        }

        if (isset($subdirs['DIM-1']) || isset($subdirs['dim-1'])) {
            $result['dim_nether'] = true;
        }

        if (isset($subdirs['DIM1']) || isset($subdirs['dim1'])) {
            $result['dim_end'] = true;
        }

        if (! $result['candidate'] && ($result['dim_nether'] || $result['dim_end'])) {
            $result['candidate'] = true;
        }

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Row assembly                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Merge probes + sibling conventions into ordered rows: the active default
     * world first, then every other primary group alphabetically, each group
     * immediately followed by its linked dimension members (nether, then end).
     *
     * @param  list<string>  $directories
     * @param  array<string, array{candidate:bool,has_region:bool,dim_nether:bool,dim_end:bool}>  $probes
     * @return list<array<string, mixed>>
     */
    private function assembleRows(Server $server, array $probes, array $directories, string $levelName, SizeCache $sizes): array
    {
        $existing = [];
        $lowered = [];

        foreach ($directories as $dir) {
            $existing[$dir] = true;
            $lowered[mb_strtolower($dir)] = $dir;
        }

        // Link pass: suffix members attach to their base folder when it exists.
        $groups = [];      // base-lowercase => [dimensionId => memberName]
        $claimed = [];     // memberLowercase => baseName

        foreach ($probes as $probe) {
            $name = $probe['name'];
            [$base, $dimensionOfSuffix] = $this->splitSuffix($name);

            if ($dimensionOfSuffix === null) {
                continue;
            }

            $anchor = $lowered[$base] ?? null;

            if ($anchor === null || mb_strtolower($anchor) === mb_strtolower($name)) {
                continue; // no base sibling anywhere / self-matching degenerate case
            }

            $key = mb_strtolower($anchor);
            $groups[$key][$dimensionOfSuffix] ??= $name;
            $claimed[mb_strtolower($name)] = $anchor;
        }

        $defaultWorld = strval($lowered[mb_strtolower($levelName)] ?? $levelName);

        $primaries = [];

        foreach ($probes as $probe) {
            $isLinkedMember = isset($claimed[mb_strtolower(strval($probe['name']))]);

            if ($isLinkedMember) {
                continue; // emitted beneath its base below
            }

            // Anchors with attached dimension members are emitted even when they
            // carry no own region/ yet (fresh installs keep worlds here); any other
            // non-candidate directory stays invisible.
            if (! $probe['candidate'] && ! isset($groups[mb_strtolower(strval($probe['name']))])) {
                continue;
            }

            $primaries[] = $probe;
        }

        usort($primaries, static function (array $a, array $b) use ($defaultWorld): int {
            [$rankA, $rankB] = [
                [mb_strtolower($a['name']) === mb_strtolower($defaultWorld) ? 0 : 1, mb_strtolower(strval($a['name']))],
                [mb_strtolower($b['name']) === mb_strtolower($defaultWorld) ? 0 : 1, mb_strtolower(strval($b['name']))],
            ];

            return $rankA <=> $rankB;
        });

        $rows = [];
        $defaultEmitted = false;

        foreach ($primaries as $primary) {
            $isDefault = mb_strtolower(strval($primary['name'])) === mb_strtolower($defaultWorld) && ! $defaultEmitted;
            $defaultEmitted = $defaultEmitted || $isDefault;

            $meta = $this->readLevelDatMeta($server, strval($primary['name']));
            $dimension = $this->classifyDimension($primary);

            $rows[] = $this->buildRow(strval($primary['name']), $isDefault, $dimension, false, $sizes, $meta);

            $baseKey = mb_strtolower(strval($primary['name']));
            $members = $groups[$baseKey] ?? [];

            foreach (self::GROUP_SUFFIXES as $suffix) {
                $dimensionId = self::LINK_SUFFIXES[$suffix];
                $member = $members[$dimensionId] ?? null;

                if ($member === null) {
                    continue;
                }

                $memberMeta = $this->readLevelDatMeta($server, $member);

                $rows[] = $this->buildRow($member, false, $dimensionId, true, $sizes, $memberMeta);
            }
        }

        return $rows;
    }

    /**
     * Dimension chip id for an UNLINKED candidate based purely on probe flags:
     * own region ⇒ overworld (nested modern dims ride along); only-nether/end
     * nesting without its own region maps to the dominated dimension; anything
     * ambiguous ends up custom.
     *
     * @param  array{candidate:bool,has_region:bool,dim_nether:bool,dim_end:bool}  $probe
     */
    private function classifyDimension(array $probe): string
    {
        if ($probe['has_region']) {
            return 'overworld';
        }

        if ($probe['dim_nether'] && ! $probe['dim_end']) {
            return 'nether';
        }

        if ($probe['dim_end'] && ! $probe['dim_nether']) {
            return 'end';
        }

        return 'custom';
    }

    /** @return array{0:string,1:'nether'|'end'|null} */
    private function splitSuffix(string $name): array
    {
        $lower = mb_strtolower($name);

        foreach (self::LINK_SUFFIXES as $suffix => $dimension) {
            if (mb_strlen($lower) > mb_strlen($suffix) && str_ends_with($lower, $suffix)) {
                return [mb_substr($name, 0, -mb_strlen($suffix)), $dimension];
            }
        }

        return [$name, null];
    }

    /**
     * Contract-shaped row builder.
     *
     * @param  array{version:?string,data_version:?int,level_name:?string,game_type:?int,hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}  $meta
     * @return array<string, mixed>
     */
    private function buildRow(string $name, bool $isDefault, string $dimension, bool $linked, SizeCache $sizes, array $meta): array
    {
        $byteSize = $sizes->estimate($name);
        $gameMode = $meta['game_type'] !== null
            ? NbtReader::GAME_TYPES[$meta['game_type']] ?? null
            : null;

        return [
            'name' => $name,
            'default' => $isDefault,
            'dimension' => $dimension,
            'linked' => $linked,
            'size' => $byteSize,
            'approximate' => $byteSize > self::SIZE_APPROXIMATE_THRESHOLD,
            'seed' => $meta['seed'],
            'game_mode' => $gameMode,
            'hardcore' => $meta['hardcore'],
            'last_played' => $meta['last_played'],
            'title' => $meta['level_name'],
            'version' => $meta['version'],
            'data_version' => $meta['data_version'],
            'spawn' => [
                'x' => $meta['spawn_x'],
                'y' => $meta['spawn_y'],
                'z' => $meta['spawn_z'],
            ],
        ];
    }

    /**
     * Level.dat metadata; every failure quietly produces the all-null shape so one
     * corrupt world cannot take down the listing.
     *
     * @return array{version:?string,data_version:?int,level_name:?string,game_type:?int,hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}
     */
    private function readLevelDatMeta(Server $server, string $directory): array
    {
        $emptyShape = [
            'version' => null,
            'data_version' => null,
            'level_name' => null,
            'game_type' => null,
            'hardcore' => false,
            'last_played' => null,
            'seed' => null,
            'spawn_x' => null,
            'spawn_y' => null,
            'spawn_z' => null,
        ];

        try {
            $bytes = $this->bridge->read($server, rtrim($directory, '/').'/level.dat', self::LEVEL_DAT_TRANSFER_CAP);
        } catch (\Throwable) {
            return $emptyShape;
        }

        if ($bytes === null || $bytes === '') {
            return $emptyShape;
        }

        try {
            $parsed = NbtReader::parseLevelDat($bytes);
        } catch (\Throwable) {
            logger()->debug('[mc-toolkit] level.dat parse failed for '.$directory);

            return $emptyShape;
        }

        return is_array($parsed) ? [...$emptyShape, ...$parsed] : $emptyShape;
    }

    private function probeList(Server $server, string $path, Budget $budget): array
    {
        if ($budget->exhausted()) {
            return [];
        }

        $budget->spend();

        return $this->bridge->safeLs($server, $path);
    }

    /* ------------------------------------------------------------------ */
    /*  Active world value                                                 */
    /* ------------------------------------------------------------------ */

    /**
     * Current `level-name` from server.properties.
     *
     * Cross-plugin dependency: {@see \McToolkit\Services\Properties\PropertiesParser}
     * (frozen stable contract owned by the wave-B Properties agent). Resolved lazily
     * behind class_exists so rolling deploys degrade to the vanilla fallback instead
     * of fataling during construction.
     */
    private function activeLevelName(Server $server): string
    {
        $parserClass = \McToolkit\Services\Properties\PropertiesParser::class;

        if (! class_exists($parserClass)) {
            return 'world';
        }

        try {
            $parser = app($parserClass);
            $values = $parser->read($server)['values'] ?? [];

            $level = trim(strval($values['level-name'] ?? ''));

            return $level !== '' ? $level : 'world';
        } catch (\Throwable) {
            return 'world';
        }
    }

    /** Level-name currently configured, exposed for the Play-on flow and imports. */
    public function currentLevelName(Server $server): string
    {
        return $this->activeLevelName($server);
    }

    /* ------------------------------------------------------------------ */
    /*  Group operations                                                   */
    /* ------------------------------------------------------------------ */

    /**
     * Directories touched by an archive/reset of `$world`: itself plus existing
     * `*_nether` / `*_the_end` siblings at root level.
     *
     * The confirmation modal lists THIS set verbatim before {@see self::reset()} runs;
     * keeping the frozen reset(): void contract intact made this preview-helper the
     * natural addition (documented as deviation #1).
     *
     * @return list<string> exact top-level names (may be empty when nothing exists).
     *
     * @throws ConnectionException when the node cannot be reached mid-existence check.
     */
    public function affectedPaths(Server $server, string $world): array
    {
        $world = basename(trim($world));

        if ($world === '' || in_array(mb_strtolower($world), self::NON_WORLD_ROOTS, true)) {
            return [];
        }

        $paths = [$world];

        foreach (self::GROUP_SUFFIXES as $suffix) {
            try {
                if ($this->bridge->exists($server, $world.$suffix)) {
                    $paths[] = $world.$suffix;
                }
            } catch (ConnectionException $exception) {
                throw $exception;
            } catch (\Throwable) {
                continue;
            }
        }

        return $paths;
    }

    /**
     * Compress the whole linked set of `$world` next to itself inside '/'.
     *
     * The PAGE download flow prefers the signed controller route (A1-owned
     * WorldDownloadController performs its own compression + streaming there and
     * enforces the exact query-signing scheme internally), so this method stays a
     * first-class service capability while the UI rides the cheaper path — see the
     * module-notes deviation #3 recorded alongside WorldsPage.
     *
     * @return string created archive file name ('' when wings omitted it)
     *
     * @throws ConnectionException|\DomainException
     */
    public function archive(Server $server, string $worldName): string
    {
        $paths = $this->affectedPaths($server, $worldName);

        if ($paths === []) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.download_failed'));
        }

        $filename = $this->bridge->compress(
            $server,
            '/',
            $paths,
            'mct-world-'.$this->kebab($worldName),
            'zip',
        );

        Activity::event('mc-toolkit:worlds.archive')
            ->subject($server)
            ->property(['world' => $worldName])
            ->log();

        return $filename;
    }

    /**
     * Delete the whole linked basename set of `$world`.
     *
     * Confirmation UX builds the preview via {@see self::affectedPaths()} BEFORE
     * calling this — the frozen contract keeps reset(): void and pushes the
     * returned-list need onto that separate helper.
     *
     * @throws ConnectionException|\DomainException
     */
    public function reset(Server $server, string $worldName): void
    {
        $paths = $this->affectedPaths($server, $worldName);

        if ($paths === []) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.permission_denied'));
        }

        $this->bridge->delete($server, $paths, '/');

        Activity::event('mc-toolkit:worlds.reset')
            ->subject($server)
            ->property(['world' => $worldName])
            ->log();
    }

    /**
     * Remote-import flow: wings pulls `$zipUrl` into '/', we poll briefly for the
     * archive, decompress it, locate the FIRST region-bearing NEW directory and
     * rename it to `$targetName` — or, unprovided, to the server's CURRENT
     * `level-name` so the imported world becomes the active one after restart.
     *
     * Name collisions fall back to `<target>-imported-<uniq4>` instead of failing
     * outright (deviation #2); wings pull latency relies on bounded polling before
     * surfacing errors.download_failed.
     *
     * @throws ConnectionException|\DomainException friendly failure shapes
     */
    public function import(Server $server, string $zipUrl, ?string $targetName = null): void
    {
        $url = trim($zipUrl);

        if ($url === '' || preg_match('/^https?:\/\//i', $url) !== 1) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.download_failed'));
        }

        $target = basename(trim(strval($targetName ?? '')));

        if ($target !== '' && preg_match('/^[A-Za-z0-9][A-Za-z0-9._\- ]*$/', $target) !== 1) {
            // lang gap #import_name_invalid — closest generic key reads misleadingly.
            throw new \DomainException('The target world name contains characters that are not supported.');
        }

        $beforeNames = $this->rootNames($server);

        $this->bridge->pull($server, $url, '/');

        $archiveFile = $this->waitForNewArchive($server, $beforeNames);

        if ($archiveFile === null) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.download_failed'));
        }

        try {
            $this->bridge->decompress($server, '/', $archiveFile);
        } catch (ConnectionException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.unpack_failed'), 0, $exception);
        }

        $newDirs = $this->newDirectories($server, $beforeNames);

        $regionDir = $this->locateRegionDir($server, $newDirs);

        if ($regionDir === null) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.unpack_failed'));
        }

        $destination = $target !== ''
            ? $target
            : $this->activeLevelName($server);

        $presentAfterImport = $this->rootNames($server, [$regionDir]);

        if (in_array($destination, $presentAfterImport, true)) {
            $destination .= '-imported-'.substr(bin2hex(random_bytes(2)), 0, 4);
        }

        $this->bridge->rename($server, $regionDir, $destination);

        Activity::event('mc-toolkit:worlds.import')
            ->subject($server)
            ->property(['world' => $destination, 'url' => $url])
            ->log();
    }

    /* ------------------------------------------------------------------ */
    /*  Import helpers                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Poll root listing until a NEW .zip/.tar* FILE appears (bounded ≈21 s total),
     * preferring the newest entry by modified time; wings pulls answer asynchronously.
     */
    private function waitForNewArchive(Server $server, array $beforeNames): ?string
    {
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $candidates = [];

            foreach ($this->bridge->safeLs($server, '/') as $entry) {
                if (($entry['directory'] ?? false) || ($entry['file'] ?? false) === false) {
                    continue;
                }

                $name = strval($entry['name']);

                if (in_array($name, $beforeNames, true)) {
                    continue;
                }

                if (preg_match('/\.(zip|tar|gz|tgz)$/i', $name) !== 1) {
                    continue;
                }

                $candidates[] = ['name' => $name, 'modified' => strval($entry['modified'] ?? '')];
            }

            if ($candidates !== []) {
                usort($candidates, static fn (array $a, array $b): int => strcmp($b['modified'], $a['modified']));

                return strval($candidates[0]['name']);
            }

            if ($attempt < 7) {
                sleep(3);
            }
        }

        return null;
    }

    /** NEW top-level DIRECTORY names (relative to the pre-import snapshot). */
    private function newDirectories(Server $server, array $before): array
    {
        $new = [];

        foreach ($this->bridge->safeLs($server, '/') as $entry) {
            if (($entry['directory'] ?? false) === false) {
                continue;
            }

            $name = strval($entry['name']);

            if (! in_array($name, $before, true)) {
                $new[] = $name;
            }
        }

        sort($new);

        return $new;
    }

    /** First region-bearing path among NEW roots, searched two levels deep. */
    private function locateRegionDir(Server $server, array $candidates): ?string
    {
        foreach ($candidates as $top) {
            if ($this->looksRegionBearing($server, strval($top), 1)) {
                return strval($top);
            }

            foreach ($this->bridge->safeLs($server, strval($top)) as $child) {
                if (($child['directory'] ?? false) === false) {
                    continue;
                }

                $nested = $top.'/'.strval($child['name']);

                if ($this->looksRegionBearing($server, $nested, 2)) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /** True when path holds raw mca files directly or nests DIM dimension region folders. */
    private function looksRegionBearing(Server $server, string $path, int $depth): bool
    {
        $entries = $this->bridge->safeLs($server, $path);

        $hasMcaFile = false;
        $dirs = [];

        foreach ($entries as $entry) {
            $name = strval($entry['name']);

            if (($entry['directory'] ?? false)) {
                $dirs[$name] = true;

                continue;
            }

            if (($entry['file'] ?? false) && str_ends_with($name, '.mca')) {
                $hasMcaFile = true;
            }
        }

        if ($hasMcaFile) {
            return true;
        }

        if ($depth <= 0) {
            return false;
        }

        foreach (['DIM-1', 'DIM1', 'DIM0'] as $dimKey) {
            if (! isset($dirs[$dimKey])) {
                continue;
            }

            foreach ($this->bridge->safeLs($server, $path.'/'.$dimKey) as $item) {
                if ((($item['directory'] ?? false)) && strval($item['name']) === 'region') {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Top-level entry NAMES currently present (optionally minus excludes).
     *
     * @param  list<string>  $exclude
     * @return list<string>
     */
    private function rootNames(Server $server, array $exclude = []): array
    {
        $names = [];

        foreach ($this->bridge->safeLs($server, '/') as $entry) {
            $name = strval($entry['name']);

            if ($name === '' || in_array($name, $exclude, true)) {
                continue;
            }

            $names[] = $name;
        }

        return $names;
    }

    private function kebab(string $value): string
    {
        return trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-') ?: 'unknown';
    }
}

/**
 * Per-invocation HTTP listing allowance so hostile filesystem shapes cannot
 * explode request counts against the node.
 *
 * @internal nothing outside McToolkit\Services\Worlds should construct these.
 */
final class Budget
{
    private int $spent = 0;

    public function __construct(private readonly int $total)
    {}

    public function spend(): void
    {
        $this->spent++;
    }

    public function exhausted(): bool
    {
        return $this->spent >= $this->total;
    }
}

/**
 * Two-level recursive byte-size estimator cached across rows sharing sub-paths.
 *
 * Sums FILE entries only — wings reports directory nodes with inconsistent size
 * columns across node versions, byte sums of files are the stable denominator.
 * Estimation degrades silently to whatever was collectable within budget; rows
 * above {@see WorldsService::SIZE_APPROXIMATE_THRESHOLD} get flagged approximate.
 *
 * @internal
 */
final class SizeCache
{
    private const MAX_TOTAL_LISTINGS = WorldsService::MAX_SIZE_LISTINGS;

    /** @var array<string, int> */
    private array $cache = [];

    private int $spent = 0;

    public function __construct(
        private readonly Server $server,
        private readonly FileBridge $bridge,
    ) {}

    public function estimate(string $directory): int
    {
        $key = mb_strtolower($directory);

        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        $value = $this->walk('/'.ltrim($directory, '/'), WorldsService::MAX_SIZE_RECURSION_LEVELS);

        $this->cache[$key] = $value;

        return $value;
    }

    private function walk(string $path, int $levelsLeft): int
    {
        if ($levelsLeft < 0 || $this->spent >= self::MAX_TOTAL_LISTINGS) {
            return 0;
        }

        $this->spent++;

        $sum = 0;

        foreach ($this->bridge->safeLs($this->server, $path) as $entry) {
            if (($entry['symlink'] ?? false)) {
                continue;
            }

            if (($entry['directory'] ?? false)) {
                $sum += $this->walk(rtrim($path, '/').'/'.strval($entry['name']), $levelsLeft - 1);

                continue;
            }

            if (($entry['file'] ?? false)) {
                $sum += max(0, (int) $entry['size']);
            }
        }

        return $sum;
    }
}
