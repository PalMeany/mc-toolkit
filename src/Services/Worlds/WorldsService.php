<?php

declare(strict_types=1);

namespace McToolkit\Services\Worlds;

use App\Enums\ContainerStatus;
use App\Models\Server;
use Illuminate\Http\Client\ConnectionException;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Nbt\NbtReader;
use McToolkit\Support\Activity;

/**
 * World-set discovery and lifecycle orchestration for the Worlds and Map pages.
 *
 * Detection model — BOTH layout conventions are supported side by side:
 *
 *  a) vanilla/Fabric/Forge: one folder holds `region/` (overworld) plus nested
 *     `DIM-1/region` and `DIM1/region` for nether/end;
 *  b) Bukkit/Paper split worlds: sibling directories `world`, `world_nether`
 *     (`DIM-1/region` inside), `world_the_end` (`DIM1/region` inside) are grouped
 *     into one logical world set.
 *
 * A directory counts as a world candidate when it directly contains `region/`,
 * or nests DIM-1/DIM1 folders. Suffix members (`*_nether`, `*_the_end`) link to the
 * base directory stripped of the suffix (case-insensitively) whenever it exists.
 *
 * Rows expose `maps`: the dimensions the map can draw for that folder, and `members`:
 * the linked sibling folders downloaded/reset together with a primary world.
 *
 * Every mutating entry point (download, reset, import) only acts on folder names the
 * discovery itself returns, and reset/import additionally require the server to be
 * stopped ({@see self::assertStopped()}).
 *
 * Cost guards: every listing goes through {@see FileBridge::safeLs()}, structural
 * probes and size summation draw from capped budgets.
 */
final class WorldsService
{
    /** Beyond this byte estimate rows render with a "~" prefix in the UI. */
    public const SIZE_APPROXIMATE_THRESHOLD = 209_715_200; // >200 MiB

    /** Directory listings allowed to ONE structure-probe pass. */
    private const MAX_PROBE_LISTINGS = 40;

    /** Listings allowed to the size estimate of ONE world folder. */
    public const MAX_SIZE_LISTINGS = 20;

    /** Recursion depth cap for size sums below each world root. */
    public const MAX_SIZE_RECURSION_LEVELS = 3;

    /** Bytes cap handed to FileBridge so corrupted gzip payloads die before inflate. */
    private const LEVEL_DAT_TRANSFER_CAP = 16_777_216;

    /** Root folders never probed as worlds (egg tooling conventions). */
    private const NON_WORLD_ROOTS = [
        'plugins', 'mods', 'libraries', 'logs', 'config', 'scripts',
        'backups', 'cache', '.toolkit', 'crash-reports', 'versions',
    ];

    /** Suffix -> logical dimension id mapping. */
    private const LINK_SUFFIXES = [
        '_nether' => 'nether',
        '_the_end' => 'end',
    ];

    /** Sibling dirs considered when composing archive/reset sets. */
    private const GROUP_SUFFIXES = ['_nether', '_the_end'];

    /** Scratch area inside the server for imports (shared with the companion bridge folder). */
    private const STAGING_ROOT = '.toolkit';

    /** Prefix of the temporary archives built for world downloads. */
    public const DOWNLOAD_ARCHIVE_PREFIX = 'mctk-download-';

    /**
     * Toolkit download archives eligible for clean-up: `mctk-download-<slug>-<8 hex>.zip`
     * (this version) and `mctk-world-<8 hex>.zip` (left behind by v1.0.x). Exact shapes
     * only, so a player's own `mctk-world-backup.zip` is never touched.
     */
    private const DOWNLOAD_ARCHIVE_PATTERN = '/^(?:mctk-download-[a-z0-9-]{1,40}-[0-9a-f]{8}|mctk-world-[0-9a-f]{8})\.zip$/';

    /** Download archives older than this are deleted (node tokens live 15 minutes). */
    private const DOWNLOAD_ARCHIVE_TTL_SECONDS = 1800;

    /** Leftover import staging folders older than this are deleted. */
    private const STAGING_TTL_SECONDS = 21_600;

    /** Listings the import may spend searching the unpacked archive. */
    private const MAX_IMPORT_LISTINGS = 30;

    /** How deep below the staging folder a world folder is searched for. */
    private const MAX_IMPORT_DEPTH = 3;

    /** Sub-folders of a world that are never candidates for "the world folder". */
    private const WORLD_INTERNALS = [
        'region', 'DIM-1', 'DIM1', 'data', 'playerdata', 'entities', 'poi', 'stats',
        'advancements', 'datapacks', '__MACOSX',
    ];

    public function __construct(
        private readonly FileBridge $bridge,
    ) {}

    /**
     * A folder name that is safe to hand to the node as one path segment: anything
     * except empty, `.`, `..`, separators and NUL (spaces and unicode are fine).
     */
    public static function isSafeDirectoryName(string $name): bool
    {
        return $name !== ''
            && $name !== '.'
            && $name !== '..'
            && strlen($name) <= 255
            && ! str_contains($name, '/')
            && ! str_contains($name, '\\')
            && ! str_contains($name, "\0");
    }

    /* ------------------------------------------------------------------ */
    /*  Listing                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Logical world inventory enriched with level.dat metadata.
     *
     * Rows carry the contract shape plus: `approximate` (size is a lower bound or above
     * {@see self::SIZE_APPROXIMATE_THRESHOLD}), `members` and `maps` (see class doc).
     * Stale toolkit download archives in the root are cleaned up on the way.
     *
     * @return list<array<string, mixed>>
     */
    public function listWorlds(Server $server, bool $withSizes = true): array
    {
        return $this->discover($server, true, $withSizes);
    }

    /**
     * Exact folder names of every world row, without level.dat reads or size walks.
     * The authority for "is this a world the toolkit may download/reset".
     *
     * @return list<string>
     */
    public function knownWorldNames(Server $server): array
    {
        return array_values(array_map(
            static fn (array $row): string => strval($row['name']),
            $this->discover($server, false, false),
        ));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function discover(Server $server, bool $enrich, bool $withSizes): array
    {
        $rootEntries = $this->bridge->safeLs($server, '/');
        $directories = [];

        foreach ($rootEntries as $entry) {
            if (($entry['directory'] ?? false) !== true) {
                continue;
            }

            $name = strval($entry['name']);

            if (! self::isSafeDirectoryName($name) || in_array(mb_strtolower($name), self::NON_WORLD_ROOTS, true)) {
                continue;
            }

            $directories[] = $name;
        }

        if ($enrich) {
            $this->sweepStaleArchives($server, $rootEntries);
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

        $sizes = $enrich && $withSizes ? new SizeCache($server, $this->bridge) : null;

        return $this->assembleRows($server, $probes, $levelName, $sizes, $enrich);
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

        $subdirs = [];

        foreach ($this->probeList($server, $name, $budget) as $entry) {
            if (($entry['directory'] ?? false) === true) {
                $subdirs[strval($entry['name'])] = true;
            }
        }

        $result['has_region'] = isset($subdirs['region']);
        $result['dim_nether'] = isset($subdirs['DIM-1']);
        $result['dim_end'] = isset($subdirs['DIM1']);
        $result['candidate'] = $result['has_region'] || $result['dim_nether'] || $result['dim_end'];

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
     * @param  array<string, array{name:string,candidate:bool,has_region:bool,dim_nether:bool,dim_end:bool}>  $probes
     * @return list<array<string, mixed>>
     */
    private function assembleRows(Server $server, array $probes, string $levelName, ?SizeCache $sizes, bool $enrich): array
    {
        $lowered = [];

        foreach (array_keys($probes) as $dir) {
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

            $anchor = $lowered[mb_strtolower($base)] ?? null;

            if ($anchor === null || mb_strtolower($anchor) === mb_strtolower($name)) {
                continue; // no base sibling / self-matching degenerate case
            }

            $key = mb_strtolower($anchor);

            if (isset($groups[$key][$dimensionOfSuffix])) {
                continue; // first member per dimension wins; later ones stay standalone
            }

            $groups[$key][$dimensionOfSuffix] = $name;
            $claimed[mb_strtolower($name)] = $anchor;
        }

        $defaultWorld = strval($lowered[mb_strtolower($levelName)] ?? $levelName);

        $primaries = [];

        foreach ($probes as $probe) {
            if (isset($claimed[mb_strtolower($probe['name'])])) {
                continue; // emitted beneath its base below
            }

            // Anchors with attached members are emitted even without their own region/
            // (fresh installs); any other non-candidate directory stays invisible.
            if (! $probe['candidate'] && ! isset($groups[mb_strtolower($probe['name'])])) {
                continue;
            }

            $primaries[] = $probe;
        }

        usort($primaries, static function (array $a, array $b) use ($defaultWorld): int {
            $rankA = [mb_strtolower($a['name']) === mb_strtolower($defaultWorld) ? 0 : 1, mb_strtolower($a['name'])];
            $rankB = [mb_strtolower($b['name']) === mb_strtolower($defaultWorld) ? 0 : 1, mb_strtolower($b['name'])];

            return $rankA <=> $rankB;
        });

        $rows = [];
        $defaultEmitted = false;

        foreach ($primaries as $primary) {
            $name = $primary['name'];
            $isDefault = ! $defaultEmitted && mb_strtolower($name) === mb_strtolower($defaultWorld);
            $defaultEmitted = $defaultEmitted || $isDefault;

            $members = [];

            foreach (self::GROUP_SUFFIXES as $suffix) {
                $member = $groups[mb_strtolower($name)][self::LINK_SUFFIXES[$suffix]] ?? null;

                if ($member !== null) {
                    $members[] = $member;
                }
            }

            $rows[] = $this->buildRow(
                $name,
                $isDefault,
                $this->classifyDimension($primary),
                false,
                $members,
                $this->mapDimensions($primary, null),
                $enrich ? $this->readLevelDatMeta($server, $name) : self::emptyMeta(),
                $sizes,
            );

            foreach ($members as $member) {
                $dimensionId = $this->splitSuffix($member)[1] ?? 'custom';
                $probe = $probes[$member];

                $rows[] = $this->buildRow(
                    $member,
                    false,
                    $dimensionId,
                    true,
                    [],
                    $this->mapDimensions($probe, $dimensionId),
                    $enrich ? $this->readLevelDatMeta($server, $member) : self::emptyMeta(),
                    $sizes,
                );
            }
        }

        return $rows;
    }

    /**
     * Dimension chip id for an UNLINKED candidate based purely on probe flags:
     * own region ⇒ overworld (nested dims ride along); only nether/end nesting maps
     * to that dimension; anything ambiguous ends up custom.
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

    /**
     * Dimensions the map can draw for one folder. Linked members are pinned to their
     * suffix dimension (Paper keeps `<level>_nether/DIM-1/region`, some forks flatten it
     * to `region/`, which MapTileService falls back to).
     *
     * @param  array{has_region:bool,dim_nether:bool,dim_end:bool}  $probe
     * @return list<string>
     */
    private function mapDimensions(array $probe, ?string $forced): array
    {
        if ($forced === 'nether') {
            return $probe['dim_nether'] || $probe['has_region'] ? ['nether'] : [];
        }

        if ($forced === 'end') {
            return $probe['dim_end'] || $probe['has_region'] ? ['end'] : [];
        }

        $dimensions = [];

        if ($probe['has_region']) {
            $dimensions[] = 'overworld';
        }

        if ($probe['dim_nether']) {
            $dimensions[] = 'nether';
        }

        if ($probe['dim_end']) {
            $dimensions[] = 'end';
        }

        return $dimensions;
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
     * @param  list<string>  $members
     * @param  list<string>  $maps
     * @param  array{version:?string,data_version:?int,level_name:?string,game_type:?int,hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}  $meta
     * @return array<string, mixed>
     */
    private function buildRow(string $name, bool $isDefault, string $dimension, bool $linked, array $members, array $maps, array $meta, ?SizeCache $sizes): array
    {
        [$byteSize, $complete] = $sizes?->estimate($name) ?? [0, true];

        $gameMode = $meta['game_type'] !== null
            ? NbtReader::GAME_TYPES[$meta['game_type']] ?? null
            : null;

        return [
            'name' => $name,
            'default' => $isDefault,
            'dimension' => $dimension,
            'linked' => $linked,
            'members' => $members,
            'maps' => $maps,
            'size' => $byteSize,
            'approximate' => ! $complete || $byteSize > self::SIZE_APPROXIMATE_THRESHOLD,
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
     * @return array{version:?string,data_version:?int,level_name:?string,game_type:?int,hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}
     */
    private static function emptyMeta(): array
    {
        return [
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
    }

    /**
     * Level.dat metadata; every failure quietly produces the all-null shape so one
     * corrupt world cannot take down the listing.
     *
     * @return array{version:?string,data_version:?int,level_name:?string,game_type:?int,hardcore:bool,last_played:?string,seed:?string,spawn_x:?int,spawn_y:?int,spawn_z:?int}
     */
    private function readLevelDatMeta(Server $server, string $directory): array
    {
        $emptyShape = self::emptyMeta();

        try {
            $bytes = $this->bridge->read($server, '/'.$directory.'/level.dat', self::LEVEL_DAT_TRANSFER_CAP);
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

    /**
     * @return list<array<string, mixed>>
     */
    private function probeList(Server $server, string $path, Budget $budget): array
    {
        if ($budget->exhausted()) {
            return [];
        }

        $budget->spend();

        return $this->bridge->safeLs($server, '/'.$path);
    }

    /* ------------------------------------------------------------------ */
    /*  Active world value                                                 */
    /* ------------------------------------------------------------------ */

    /** Current `level-name` from server.properties ('world' when unreadable). */
    private function activeLevelName(Server $server): string
    {
        try {
            $values = app(\McToolkit\Services\Properties\PropertiesParser::class)->read($server)['values'] ?? [];

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
     * Top-level folders touched by a download/reset of `$world`: the world itself plus,
     * for a primary world, its linked `*_nether` / `*_the_end` members. Empty when
     * `$world` is not a folder the discovery lists (exact name match).
     *
     * @return list<string>
     */
    public function affectedPaths(Server $server, string $world): array
    {
        if (! self::isSafeDirectoryName($world)) {
            return [];
        }

        foreach ($this->discover($server, false, false) as $row) {
            if ($row['name'] === $world) {
                return array_values(array_unique([$row['name'], ...$row['members']]));
            }
        }

        return [];
    }

    /**
     * Server must be STOPPED (and not suspended/transferring) before files of a world
     * are deleted or replaced: a running server keeps rewriting level.dat and loaded
     * chunks after the fact. The status is re-read instead of trusting the 15 s cache.
     *
     * @throws \DomainException with a translated, user-facing reason
     */
    public function assertStopped(Server $server): void
    {
        if ($server->isInConflictState()) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.server_unavailable'));
        }

        cache()->forget("servers.$server->uuid.status");

        $status = $server->retrieveStatus();

        if ($status === ContainerStatus::Missing) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.wings_502'));
        }

        $stopped = [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Created, ContainerStatus::Dead];

        if (! in_array($status, $stopped, true)) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.stopped_required'));
        }
    }

    /**
     * Compresses the linked set of `$world` into a temporary zip in the server root and
     * returns its file name, for the node's own signed download endpoint. The archive
     * is removed by {@see self::sweepStaleArchives()} once older than
     * {@see self::DOWNLOAD_ARCHIVE_TTL_SECONDS}.
     *
     * @throws \DomainException when `$world` is not a known world
     * @throws ConnectionException|\RuntimeException
     */
    public function prepareDownload(Server $server, string $world): string
    {
        $paths = $this->affectedPaths($server, $world);

        if ($paths === []) {
            throw new \DomainException(trans('minecraft-toolkit::pages.worlds.unknown_world'));
        }

        $this->sweepStaleArchives($server);

        $archive = $this->bridge->compress(
            $server,
            '/',
            $paths,
            self::DOWNLOAD_ARCHIVE_PREFIX.$this->slug($world).'-'.bin2hex(random_bytes(4)),
            'zip',
        );

        if ($archive === '') {
            throw new \RuntimeException('Wings did not return an archive name.');
        }

        return $archive;
    }

    /** Removes one download archive right away (failure paths). Never throws. */
    public function discardArchive(Server $server, string $archive): void
    {
        if (preg_match(self::DOWNLOAD_ARCHIVE_PATTERN, $archive) !== 1) {
            return;
        }

        try {
            $this->bridge->delete($server, [$archive], '/');
        } catch (\Throwable) {
            // the sweep picks it up later
        }
    }

    /**
     * Deletes toolkit download archives in the server root older than the TTL
     * (including archives left behind by v1.0.x). Never throws.
     *
     * @param  list<array<string, mixed>>|null  $rootEntries  a fresh root listing, when the caller has one
     */
    public function sweepStaleArchives(Server $server, ?array $rootEntries = null): void
    {
        try {
            $stale = [];
            $threshold = time() - self::DOWNLOAD_ARCHIVE_TTL_SECONDS;

            foreach ($rootEntries ?? $this->bridge->safeLs($server, '/') as $entry) {
                $name = strval($entry['name'] ?? '');

                if (($entry['file'] ?? false) !== true || preg_match(self::DOWNLOAD_ARCHIVE_PATTERN, $name) !== 1) {
                    continue;
                }

                $modified = strtotime(strval($entry['modified'] ?? ''));

                if ($modified !== false && $modified < $threshold) {
                    $stale[] = $name;
                }
            }

            if ($stale !== []) {
                $this->bridge->delete($server, $stale, '/');
            }
        } catch (\Throwable) {
            // best effort, next listing retries
        }
    }

    /**
     * Delete the whole linked set of `$world` (server must be stopped).
     *
     * @throws ConnectionException|\DomainException
     */
    public function reset(Server $server, string $worldName): void
    {
        $this->assertStopped($server);

        $paths = $this->affectedPaths($server, $worldName);

        if ($paths === []) {
            throw new \DomainException(trans('minecraft-toolkit::pages.worlds.unknown_world'));
        }

        $this->bridge->delete($server, $paths, '/');

        Activity::event('mc-toolkit:worlds.reset')
            ->subject($server)
            ->property(['world' => $worldName])
            ->log();
    }

    /**
     * Remote-import flow (server must be stopped):
     *
     *  1. wings pulls `$zipUrl` in the FOREGROUND into a fresh staging folder
     *     `.toolkit/import-<id>/` under a fixed file name;
     *  2. the archive is unpacked THERE (never in the server root, so nothing live can
     *     be overwritten) and deleted;
     *  3. the world folder is located (shallowest folder holding `level.dat`, else
     *     `region/`; flat archives make the staging folder itself the world);
     *  4. it is moved to `$targetName` — or the current `level-name` — under a name that
     *     is free at the root (`<name>-imported-<hex>` otherwise); Bukkit-style
     *     `<world>_nether` / `<world>_the_end` siblings follow as `<dest>_nether` /
     *     `<dest>_the_end`;
     *  5. the staging folder is removed whatever happened.
     *
     * @return string the root folder name the world was imported as
     *
     * @throws ConnectionException|\DomainException friendly failure shapes
     */
    public function import(Server $server, string $zipUrl, ?string $targetName = null): string
    {
        $url = trim($zipUrl);

        if (preg_match('/^https?:\/\//i', $url) !== 1 || filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \DomainException(trans('minecraft-toolkit::errors.statuses.download_failed'));
        }

        $target = trim(strval($targetName ?? ''));

        if ($target !== '' && (! self::isSafeDirectoryName($target) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\- ]*$/', $target) !== 1)) {
            throw new \DomainException(trans('minecraft-toolkit::pages.worlds.import_name_invalid'));
        }

        $this->assertStopped($server);
        $this->sweepStaleStaging($server);

        $staging = self::STAGING_ROOT.'/import-'.bin2hex(random_bytes(6));
        $archive = $this->archiveFileName($url);

        try {
            try {
                $this->bridge->pull($server, $url, '/'.$staging, $archive);
            } catch (\Throwable $exception) {
                throw new \DomainException(trans('minecraft-toolkit::errors.statuses.download_failed'), 0, $exception);
            }

            try {
                $this->bridge->decompress($server, '/'.$staging, $archive);
                $this->bridge->delete($server, [$archive], '/'.$staging);
            } catch (\Throwable $exception) {
                throw new \DomainException(trans('minecraft-toolkit::errors.statuses.unpack_failed'), 0, $exception);
            }

            $worldPath = $this->locateWorld($server, $staging);

            if ($worldPath === null) {
                throw new \DomainException(trans('minecraft-toolkit::pages.worlds.import_no_world'));
            }

            $siblings = $this->importSiblings($server, $worldPath, $staging);

            // Re-check right before touching the root: the download may have taken minutes.
            $this->assertStopped($server);

            $destination = $this->freeDestination(
                $server,
                $target !== '' ? $target : $this->activeLevelName($server),
            );

            $this->bridge->rename($server, $worldPath, $destination);

            if (! $this->bridge->exists($server, $destination)) {
                throw new \DomainException(trans('minecraft-toolkit::errors.statuses.unpack_failed'));
            }

            foreach ($siblings as $suffix => $siblingPath) {
                try {
                    $this->bridge->rename($server, $siblingPath, $destination.$suffix);
                } catch (\Throwable $exception) {
                    report($exception); // the main world is in place; a lost sibling is not fatal
                }
            }
        } finally {
            try {
                $this->bridge->delete($server, [$staging], '/');
            } catch (\Throwable) {
                // already moved away (flat archive) or gone; the next import sweeps leftovers
            }
        }

        Activity::event('mc-toolkit:worlds.import')
            ->subject($server)
            ->property(['world' => $destination, 'url' => $url])
            ->log();

        return $destination;
    }

    /* ------------------------------------------------------------------ */
    /*  Import helpers                                                     */
    /* ------------------------------------------------------------------ */

    /** Fixed archive name keeping the URL's archive extension (wings sniffs content too). */
    private function archiveFileName(string $url): string
    {
        $path = strtolower(strval(parse_url($url, PHP_URL_PATH) ?? ''));

        foreach (['.tar.gz', '.tgz', '.tar.bz2', '.tar.xz', '.tar', '.zip', '.7z', '.rar'] as $extension) {
            if (str_ends_with($path, $extension)) {
                return 'world-archive'.$extension;
            }
        }

        return 'world-archive.zip';
    }

    /**
     * Breadth-first search below the staging folder for the world folder: the first
     * folder holding `level.dat` that is not itself a `_nether`/`_the_end` member wins;
     * otherwise the first folder holding `level.dat` or `region/`. World folders are
     * not searched further (their sub-folders are world internals).
     */
    private function locateWorld(Server $server, string $staging): ?string
    {
        $queue = [[$staging, 0]];
        $listings = 0;
        $fallback = null;

        while ($queue !== [] && $listings < self::MAX_IMPORT_LISTINGS) {
            [$path, $depth] = array_shift($queue);
            $listings++;

            $hasLevelDat = false;
            $hasRegion = false;
            $children = [];

            foreach ($this->bridge->safeLs($server, '/'.$path) as $entry) {
                $name = strval($entry['name'] ?? '');

                if (! self::isSafeDirectoryName($name)) {
                    continue;
                }

                if (($entry['directory'] ?? false) === true) {
                    $hasRegion = $hasRegion || $name === 'region';

                    if (! in_array($name, self::WORLD_INTERNALS, true)) {
                        $children[] = $name;
                    }
                } elseif (($entry['file'] ?? false) === true && $name === 'level.dat') {
                    $hasLevelDat = true;
                }
            }

            if ($hasLevelDat || $hasRegion) {
                $isMember = $path !== $staging && $this->splitSuffix(basename($path))[1] !== null;

                if ($hasLevelDat && ! $isMember) {
                    return $path;
                }

                $fallback ??= $path;

                continue;
            }

            if ($depth < self::MAX_IMPORT_DEPTH) {
                sort($children);

                foreach ($children as $child) {
                    $queue[] = [$path.'/'.$child, $depth + 1];
                }
            }
        }

        return $fallback;
    }

    /**
     * Bukkit-style dimension siblings next to the located world (`<base>_nether`,
     * `<base>_the_end`), keyed by suffix.
     *
     * @return array<string, string>
     */
    private function importSiblings(Server $server, string $worldPath, string $staging): array
    {
        if ($worldPath === $staging) {
            return [];
        }

        $parent = dirname($worldPath);
        $base = basename($worldPath);
        $present = [];

        foreach ($this->bridge->safeLs($server, '/'.$parent) as $entry) {
            if (($entry['directory'] ?? false) === true) {
                $present[strval($entry['name'])] = true;
            }
        }

        $siblings = [];

        foreach (self::GROUP_SUFFIXES as $suffix) {
            if (isset($present[$base.$suffix])) {
                $siblings[$suffix] = $parent.'/'.$base.$suffix;
            }
        }

        return $siblings;
    }

    /**
     * A root name that is free for the world AND its possible `_nether`/`_the_end`
     * siblings (case-insensitive, as players move worlds between file systems).
     */
    private function freeDestination(Server $server, string $preferred): string
    {
        $taken = [];

        foreach ($this->rootNames($server) as $name) {
            $taken[mb_strtolower($name)] = true;
        }

        $isTaken = static function (string $candidate) use ($taken): bool {
            foreach (['', ...self::GROUP_SUFFIXES] as $suffix) {
                if (isset($taken[mb_strtolower($candidate.$suffix)])) {
                    return true;
                }
            }

            return false;
        };

        $base = self::isSafeDirectoryName($preferred) ? $preferred : 'world';
        $candidate = $base;

        for ($attempt = 0; $isTaken($candidate); $attempt++) {
            if ($attempt >= 20) {
                throw new \DomainException(trans('minecraft-toolkit::pages.worlds.import_name_invalid'));
            }

            $candidate = $base.'-imported-'.bin2hex(random_bytes(2));
        }

        return $candidate;
    }

    /** Removes `.toolkit/import-*` folders a crashed import left behind. Never throws. */
    private function sweepStaleStaging(Server $server): void
    {
        try {
            $stale = [];
            $threshold = time() - self::STAGING_TTL_SECONDS;

            foreach ($this->bridge->safeLs($server, '/'.self::STAGING_ROOT) as $entry) {
                $name = strval($entry['name'] ?? '');

                if (($entry['directory'] ?? false) !== true || ! str_starts_with($name, 'import-')) {
                    continue;
                }

                $modified = strtotime(strval($entry['modified'] ?? ''));

                if ($modified !== false && $modified < $threshold) {
                    $stale[] = $name;
                }
            }

            if ($stale !== []) {
                $this->bridge->delete($server, $stale, '/'.self::STAGING_ROOT);
            }
        } catch (\Throwable) {
            // best effort
        }
    }

    /**
     * Top-level entry NAMES currently present.
     *
     * @return list<string>
     */
    private function rootNames(Server $server): array
    {
        $names = [];

        foreach ($this->bridge->safeLs($server, '/') as $entry) {
            $name = strval($entry['name'] ?? '');

            if ($name !== '') {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function slug(string $value): string
    {
        $slug = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '-', $value) ?? '', '-'));

        return substr($slug !== '' ? $slug : 'world', 0, 40);
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

    public function __construct(private readonly int $total) {}

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
 * Recursive byte-size estimator, one listing budget per world folder.
 *
 * Sums FILE entries only — wings reports directory nodes with inconsistent size
 * columns across node versions. When the budget runs out the estimate is a lower
 * bound and flagged incomplete, so the row renders as approximate.
 *
 * @internal
 */
final class SizeCache
{
    /** @var array<string, array{0:int, 1:bool}> */
    private array $cache = [];

    public function __construct(
        private readonly Server $server,
        private readonly FileBridge $bridge,
    ) {}

    /**
     * @return array{0:int, 1:bool} [bytes, complete]
     */
    public function estimate(string $directory): array
    {
        if (isset($this->cache[$directory])) {
            return $this->cache[$directory];
        }

        $budget = new Budget(WorldsService::MAX_SIZE_LISTINGS);
        $complete = true;

        $bytes = $this->walk('/'.ltrim($directory, '/'), WorldsService::MAX_SIZE_RECURSION_LEVELS, $budget, $complete);

        return $this->cache[$directory] = [$bytes, $complete];
    }

    private function walk(string $path, int $levelsLeft, Budget $budget, bool &$complete): int
    {
        if ($levelsLeft < 0) {
            return 0;
        }

        if ($budget->exhausted()) {
            $complete = false;

            return 0;
        }

        $budget->spend();

        $sum = 0;

        foreach ($this->bridge->safeLs($this->server, $path) as $entry) {
            if (($entry['symlink'] ?? false) === true) {
                continue;
            }

            if (($entry['directory'] ?? false) === true) {
                $sum += $this->walk(rtrim($path, '/').'/'.strval($entry['name']), $levelsLeft - 1, $budget, $complete);

                continue;
            }

            if (($entry['file'] ?? false) === true) {
                $sum += max(0, (int) ($entry['size'] ?? 0));
            }
        }

        return $sum;
    }
}
