<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use App\Enums\ContainerStatus;
use App\Facades\Activity;
use App\Models\Server;
use Closure;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\Registries\CurseForgeService;
use McToolkit\Services\Catalog\Registries\HangarService;
use McToolkit\Services\Catalog\Registries\ModrinthService;
use McToolkit\Services\Catalog\Registries\SpigetService;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use Throwable;

/**
 * Installs plugins / mods / modpacks onto a server through the panel's file bridge.
 *
 * Owner: A3. Public API frozen by the contract (the trailing $isUpdate flag is additive):
 *
 *   installProject(Server $server, string $kind, array $project, array $versionMeta, bool $isUpdate = false): InstalledContent
 *   installed(Server $server, string $kind): array           // ['tracked'=>…, 'foreign'=>names+sizes]
 *   forgetMissing(Server $server): void                      // parity with addon v1.1.8
 *
 * Behavioural rules (contract §installer + wave brief):
 *  - SAFETY GATE: never touch a server in a conflict state (suspended, transferring,
 *    restoring a backup, installing, node in maintenance) nor one whose container is
 *    mid-transition. Single jars may land while the server runs; modpack installs
 *    REQUIRE a fully stopped container (Offline/Exited/Created).
 *  - Every download is a FOREGROUND wings pull with an explicit file name, so the
 *    tracked name is exactly the on-disk name and failures surface synchronously.
 *    Wings follows redirects itself and refuses internal-network targets.
 *  - The loader is never relaxed when picking a build (a jar for another loader cannot
 *    run at all); only the game version widens when nothing matches it exactly.
 *  - Updates retire the previously tracked file of the same project once the new one
 *    landed, so a server never ends up with two versions of one plugin.
 *  - Services never notify: friendly DomainExceptions whose messages come from
 *    trans('minecraft-toolkit::errors.statuses.*') — pages own notifications.
 *  - Every successful mutation logs mc-toolkit:content.install (or .update) with the
 *    `title` + `source` properties the lang/en/activity.php labels expect.
 *
 * Tracked file names: single plugin/mod rows store the bare jar name (their folder
 * follows from the kind), modpack files store their path relative to the server root.
 */
final class ContentInstallerService
{
    /** Canonical internal kinds accepted by installProject()/installed(). */
    private const KIND_ALIASES = [
        'plugins' => 'plugin',
        'mods' => 'mod',
        'modpacks' => 'modpack',
        'plugin' => 'plugin',
        'mod' => 'mod',
        'modpack' => 'modpack',
    ];

    /** Registry ids accepted by installProject(); 'spigot' (search chip key) aliases the Spiget id. */
    private const SOURCE_ALIASES = [
        'modrinth' => 'modrinth',
        'hangar' => 'hangar',
        'spigot' => 'spigotmc',
        'spigotmc' => 'spigotmc',
        'curseforge' => 'curseforge',
    ];

    /** Display names used in activity log entries. */
    private const SOURCE_LABELS = [
        'modrinth' => 'Modrinth',
        'hangar' => 'Hangar',
        'spigotmc' => 'SpigotMC',
        'curseforge' => 'CurseForge',
    ];

    /** Container states considered "fully stopped" for the modpack requirement. */
    private const STOPPED_OK = [
        ContainerStatus::Offline,
        ContainerStatus::Exited,
        ContainerStatus::Created,
    ];

    /** Container states mid-transition — unsafe to write regardless of kind. */
    private const TRANSITIONING = [
        ContainerStatus::Starting,
        ContainerStatus::Stopping,
        ContainerStatus::Restarting,
        ContainerStatus::Removing,
    ];

    /** Loader tags each detected software can load, most specific first. */
    private const SOFTWARE_LOADERS = [
        'paper' => ['paper', 'spigot', 'bukkit'],
        'pufferfish' => ['paper', 'spigot', 'bukkit'],
        'leaves' => ['paper', 'spigot', 'bukkit'],
        'purpur' => ['purpur', 'paper', 'spigot', 'bukkit'],
        'folia' => ['folia'],
        'spigot' => ['spigot', 'bukkit'],
        'mohist' => ['spigot', 'bukkit'],
        'arclight' => ['spigot', 'bukkit'],
        'sponge' => ['sponge'],
        'fabric' => ['fabric'],
        'quilt' => ['quilt', 'fabric'],
        'forge' => ['forge'],
        'neoforge' => ['neoforge'],
        'velocity' => ['velocity'],
        'waterfall' => ['waterfall', 'bungeecord'],
        'bungeecord' => ['bungeecord', 'waterfall'],
    ];

    /** Loader tags that make sense per content kind. */
    private const PLUGIN_LOADERS = ['paper', 'spigot', 'bukkit', 'purpur', 'folia', 'sponge', 'velocity', 'waterfall', 'bungeecord'];

    private const MOD_LOADERS = ['fabric', 'quilt', 'forge', 'neoforge'];

    /** Hosts a .mrpack may download from (Modrinth modpack format, `downloads` allow-list). */
    private const MRPACK_ALLOWED_HOSTS = ['cdn.modrinth.com', 'github.com', 'raw.githubusercontent.com', 'gitlab.com'];

    /** Server-root file that documents (and anchors the tracking row of) the installed modpack. */
    private const MRPACK_INDEX = 'modrinth.index.json';

    /** Modpack staging folder prefix in the server root; removed again in every outcome. */
    private const STAGING_PREFIX = '.mctoolkit-staging-';

    /** Depth guard for the recursive overrides merge. */
    private const MAX_MERGE_DEPTH = 24;

    public function __construct(
        private readonly FileBridge $files,
        private readonly DetectionService $detection,
        private readonly ModrinthService $modrinth,
        private readonly HangarService $hangar,
        private readonly SpigetService $spiget,
        private readonly CurseForgeService $curseForge,
    ) {}

    /**
     * Install ONE best-matching artifact of `$project` for this server.
     *
     * @param  string  $kind  plugin|mod|modpack (plurals tolerated)
     * @param  array<string, mixed>  $project  uniform catalog item (ContentSearchService shape);
     *               required keys: source, id — title is looked up when missing
     * @param  array<string, mixed>  $versionMeta  constraint bag from detection:
     *               ['software' => 'purpur', 'loader' => 'bukkit', 'mc_version' => '1.21.3']
     *               (an explicit ['loaders' => [...]] list overrides the software mapping)
     * @param  bool  $isUpdate  log as an update (also implied when the project is already tracked)
     * @throws DomainException translation-backed whenever anything blocks or fails
     */
    public function installProject(Server $server, string $kind, array $project, array $versionMeta = [], bool $isUpdate = false): InstalledContent
    {
        $kindKey = strtolower(trim($kind));
        $kind = self::KIND_ALIASES[$kindKey] ?? throw new \InvalidArgumentException("Unsupported content kind [{$kindKey}].");

        $rawSource = strtolower(trim((string) ($project['source'] ?? '')));
        $source = self::SOURCE_ALIASES[$rawSource] ?? throw $this->fail('download_failed', "Source [{$rawSource}] cannot install.");

        $projectId = trim((string) ($project['id'] ?? ''));

        throw_if($projectId === '', new \InvalidArgumentException('The project item carries no usable id.'));

        $this->assertInstallSafe($server, $kind === 'modpack');

        $title = trim((string) ($project['title'] ?? ''));

        if ($title === '' || $title === $projectId) {
            $title = $this->lookupTitle($source, $projectId) ?? $projectId;
        }

        if ($kind === 'modpack') {
            return $this->installModpack($server, $source, $projectId, $title, $isUpdate);
        }

        $loaders = $this->loaderCandidates($kind, $versionMeta);
        $mcVersion = trim((string) ($versionMeta['mc_version'] ?? ''));

        $artifact = $this->fromRegistry(fn (): array => match ($source) {
            'modrinth' => $this->resolveModrinthFile($projectId, $loaders, $mcVersion, $title),
            'spigotmc' => $this->resolveSpigetDownload($projectId, $title),
            'hangar' => $this->resolveHangarDownload($projectId, $loaders, $mcVersion, $title),
            'curseforge' => $this->resolveCurseForgeFile($projectId, $loaders, $mcVersion, $title),
            default => throw $this->fail('download_failed', "Source [{$source}] cannot install."),
        });

        $this->assertJar($artifact['file']);

        $targetDir = $this->targetDir($server, $kind);

        // Rows of this project tracked BEFORE this run — retired once the new file is in place.
        $previous = $this->standaloneRows($server, $kind, $source, $projectId);
        $isUpdate = $isUpdate || $previous->isNotEmpty();

        $fileName = $this->pullArtifact($server, $artifact, $targetDir);

        $summary = ['mode' => 'single', 'target_dir' => $targetDir];

        // Mods page parity promise ("with their dependencies"): every REQUIRED Modrinth
        // dependency of the chosen build, one level deep. Failures never undo the main file.
        if ($source === 'modrinth' && $artifact['dependencies'] !== []) {
            $summary['dependencies'] = $this->installRequiredDependencies(
                $server, $kind, $artifact['dependencies'], $loaders, $mcVersion, $targetDir,
            );
        }

        $row = $this->trackRow($server, $kind, $source, $projectId, $fileName, $artifact, [
            'title' => $title,
            'summary' => $summary,
        ]);

        $this->retireReplaced($server, $previous, $row);

        $this->logInstall($server, $isUpdate, $title, $source, $fileName, 0);

        return $row;
    }

    /**
     * Rows tracked for this server+kind merged with a scan of the real directory.
     *
     * `tracked` returns InstalledContent model instances; `foreign` lists every *.jar
     * sitting in the same directory NOT known to the tracker (name lowercased compare).
     *
     * @return array{tracked:list<InstalledContent>, foreign:list<array{name:string, size:?int}>}
     */
    public function installed(Server $server, string $kind): array
    {
        $kind = self::KIND_ALIASES[strtolower(trim($kind))] ?? throw new \InvalidArgumentException(
            "Unsupported content kind [{$kind}].",
        );

        /** @var Collection<int, InstalledContent> $rows */
        $rows = InstalledContent::query()
            ->where('server_id', $server->getKey())
            ->where('kind', $kind)
            ->orderByDesc('created_at')
            ->get();

        $known = [];

        foreach ($rows as $row) {
            $base = mb_strtolower(basename(str_replace('\\', '/', (string) $row->file_name)));

            if ($base !== '') {
                $known[$base] = true;
            }
        }

        $foreign = [];

        if ($kind !== 'modpack') {
            foreach ($this->listJars($server, $this->targetDir($server, $kind)) as $entry) {
                $name = (string) ($entry['name'] ?? '');

                if (!isset($known[mb_strtolower($name)])) {
                    $foreign[] = [
                        'name' => $name,
                        'size' => isset($entry['size']) && is_numeric($entry['size']) ? (int) $entry['size'] : null,
                    ];
                }
            }
        }

        return ['tracked' => $rows->all(), 'foreign' => $foreign];
    }

    /**
     * Prune tracked rows whose underlying file vanished from the disk.
     * Parity with addon v1.1.8's `forgetMissing`.
     *
     * Each row is looked up in the folder it actually lives in (bare plugin/mod names
     * resolve to the plugins/mods folder, pack files carry their own relative path).
     * Directory listing failures NEVER wipe rows blindly — that bucket is skipped so a
     * temporarily unreachable node cannot mass-delete user history.
     */
    public function forgetMissing(Server $server): void
    {
        /** @var Collection<int, InstalledContent> $rows */
        $rows = InstalledContent::query()->where('server_id', $server->getKey())->get();

        if ($rows->isEmpty()) {
            return;
        }

        $dirs = $this->contentDirs($server);

        // ['<dir>' => ['<basename-lower>' => [keys]]]
        $groups = [];

        foreach ($rows as $row) {
            $path = $this->pathOfRow($row, $dirs);

            if ($path !== null) {
                $groups[dirname($path)][mb_strtolower(basename($path))][] = $row->getKey();
            }
        }

        $toForget = [];

        foreach ($groups as $directory => $basenames) {
            try {
                $entries = $this->files->ls($server, (string) $directory);
            } catch (Throwable) {
                continue;
            }

            $present = [];

            foreach ($entries as $entry) {
                $present[mb_strtolower((string) $entry['name'])] = true;
            }

            foreach ($basenames as $baseLower => $keys) {
                if (!isset($present[(string) $baseLower])) {
                    array_push($toForget, ...$keys);
                }
            }
        }

        if ($toForget !== []) {
            InstalledContent::query()->whereKey($toForget)->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Safety gate
    // -------------------------------------------------------------------------

    /**
     * Blocking rules:
     *  - conflict states (suspended / transfer / backup restore / installing / maintenance)
     *  - transitioning container states
     *  - running containers: allowed for single jars, refused for modpacks.
     *
     * @throws DomainException
     */
    private function assertInstallSafe(Server $server, bool $requireStopped): void
    {
        if ($server->isInConflictState()) {
            throw $this->fail('server_unavailable');
        }

        if ($requireStopped) {
            // Pelican caches the container state for 15s (Server::retrieveStatus()); a pack
            // install right after "Stop" must judge the fresh state.
            cache()->forget("servers.{$server->uuid}.status");
        }

        try {
            $live = $server->retrieveStatus();
        } catch (Throwable $e) {
            throw $this->fail('wings_502', 'Status could not be checked.', $e);
        }

        if (in_array($live, self::TRANSITIONING, true)) {
            throw $this->fail('stopped_required', sprintf('The container is %s.', $live->value));
        }

        if ($requireStopped && !in_array($live, self::STOPPED_OK, true)) {
            throw $this->fail('stopped_required');
        }
    }

    // -------------------------------------------------------------------------
    // Artifact resolution
    // -------------------------------------------------------------------------

    /**
     * Run a registry lookup and turn transport/HTTP failures into friendly errors.
     *
     * @param  Closure(): array<string, mixed>  $resolve
     * @return array<string, mixed>
     */
    private function fromRegistry(Closure $resolve): array
    {
        try {
            return $resolve();
        } catch (DomainException $e) {
            throw $e;
        } catch (RequestException $e) {
            $status = $e->response->status();

            throw $this->fail($status === 429 ? 'rate_limited' : 'download_failed', sprintf('The registry answered HTTP %d.', $status), $e);
        } catch (ConnectionException $e) {
            throw $this->fail('download_failed', 'The registry could not be reached.', $e);
        }
    }

    /**
     * Loader tags the target server can load for this kind of content.
     *
     * @param  array<string, mixed>  $versionMeta
     * @return list<string>
     */
    private function loaderCandidates(string $kind, array $versionMeta): array
    {
        $allowed = $kind === 'plugin' ? self::PLUGIN_LOADERS : self::MOD_LOADERS;

        if (is_array($versionMeta['loaders'] ?? null)) {
            $explicit = array_values(array_intersect(
                array_map('strtolower', array_filter($versionMeta['loaders'], fn ($l) => is_string($l))),
                $allowed,
            ));

            if ($explicit !== []) {
                return $explicit;
            }
        }

        $software = strtolower(trim((string) ($versionMeta['software'] ?? '')));
        // Older callers passed the software token itself as 'loader'.
        $loader = strtolower(trim((string) ($versionMeta['loader'] ?? '')));

        foreach ([$software, $loader] as $token) {
            if (isset(self::SOFTWARE_LOADERS[$token])) {
                // A plugin page on a Fabric box (pre-placing before a software swap) still wants
                // Bukkit builds, so candidates of the wrong kind fall through to the defaults.
                $candidates = array_values(array_intersect(self::SOFTWARE_LOADERS[$token], $allowed));

                if ($candidates !== []) {
                    return $candidates;
                }
            }
        }

        if ($kind === 'mod') {
            return match ($loader) {
                'fabric' => ['fabric'],
                'forge' => ['forge'],
                default => [],
            };
        }

        return match ($loader) {
            'velocity' => ['velocity'],
            default => ['paper', 'spigot', 'bukkit'],
        };
    }

    /**
     * Best matching non-modpack Modrinth file honoring loader/mc-version hints.
     *
     * @param  list<string>  $loaders
     * @return array{url:string, file:string, version:?string, checksum:?string, dependencies:list<string>}
     */
    private function resolveModrinthFile(string $projectId, array $loaders, string $mcVersion, string $title): array
    {
        foreach (self::releasesFirst($this->modrinthVersionsFor($projectId, $loaders, $mcVersion)) as $version) {
            $file = $this->primaryModrinthFile($version);
            $url = is_array($file) && is_string($file['url'] ?? null) ? $file['url'] : '';

            if ($url === '') {
                continue;
            }

            $hashes = is_array($file['hashes'] ?? null) ? $file['hashes'] : [];
            $dependencies = [];

            foreach (is_array($version['dependencies'] ?? null) ? $version['dependencies'] : [] as $dependency) {
                if (
                    is_array($dependency)
                    && ($dependency['dependency_type'] ?? '') === 'required'
                    && is_string($dependency['project_id'] ?? null)
                    && $dependency['project_id'] !== ''
                    && $dependency['project_id'] !== $projectId
                ) {
                    $dependencies[] = $dependency['project_id'];
                }
            }

            return [
                'url' => $url,
                'file' => $this->sanitizeFileName(is_string($file['filename'] ?? null) ? $file['filename'] : '', $title),
                'version' => is_string($version['version_number'] ?? null) ? $version['version_number'] : null,
                'checksum' => is_string($hashes['sha1'] ?? null) ? $hashes['sha1'] : null,
                'dependencies' => array_values(array_unique($dependencies)),
            ];
        }

        throw $this->fail('download_failed', $loaders === []
            ? 'No published build was found.'
            : sprintf('No build for %s was found.', implode(' / ', $loaders)));
    }

    /**
     * Modrinth versions honoring hints. The loader filter is never dropped — only the game
     * version widens when no build targets it exactly.
     *
     * @param  list<string>  $loaders
     * @return list<array<string, mixed>>
     */
    private function modrinthVersionsFor(string $projectId, array $loaders, string $mcVersion): array
    {
        $loaderParam = $loaders !== [] ? (string) json_encode(array_values($loaders)) : null;
        $versionParam = $mcVersion !== '' ? (string) json_encode([$mcVersion]) : null;

        $attempts = [];

        if ($loaderParam !== null) {
            if ($versionParam !== null) {
                $attempts[] = ['loaders' => $loaderParam, 'game_versions' => $versionParam];
            }

            $attempts[] = ['loaders' => $loaderParam];
        } else {
            if ($versionParam !== null) {
                $attempts[] = ['game_versions' => $versionParam];
            }

            $attempts[] = [];
        }

        foreach ($attempts as $attempt) {
            $results = $this->modrinth->versions($projectId, $attempt);

            if ($results !== []) {
                return $results;
            }
        }

        return [];
    }

    /**
     * One-level-deep pull of every REQUIRED Modrinth dependency named on the chosen build.
     * Each dependency is tracked under its own project_id (update-dottable); one that is
     * already tracked, or already sitting in the folder as a hand-placed jar, is left alone
     * — two copies of one mod refuse to load together.
     *
     * @param  list<string>  $dependencyIds
     * @param  list<string>  $loaders
     * @return array<string, string> project id => installed|already_installed|already_present|failed: …
     */
    private function installRequiredDependencies(Server $server, string $kind, array $dependencyIds, array $loaders, string $mcVersion, string $targetDir): array
    {
        $report = [];
        $present = null;

        foreach ($dependencyIds as $dependencyId) {
            if (isset($report[$dependencyId])) {
                continue;
            }

            if ($this->standaloneRows($server, $kind, 'modrinth', $dependencyId)->isNotEmpty()) {
                $report[$dependencyId] = 'already_installed';

                continue;
            }

            try {
                $project = $this->modrinth->project($dependencyId);
                $depTitle = is_string($project['title'] ?? null) && $project['title'] !== '' ? $project['title'] : $dependencyId;
                $slug = is_string($project['slug'] ?? null) ? mb_strtolower($project['slug']) : '';

                $present ??= array_map('strval', array_keys($this->scanNames($server, $targetDir)));

                if ($slug !== '' && self::listingHasProject($present, $slug)) {
                    $report[$dependencyId] = 'already_present';

                    continue;
                }

                $artifact = $this->fromRegistry(fn (): array => $this->resolveModrinthFile($dependencyId, $loaders, $mcVersion, $depTitle));
                $this->assertJar($artifact['file']);

                $fileName = $this->pullArtifact($server, $artifact, $targetDir);

                $this->trackRow($server, $kind, 'modrinth', $dependencyId, $fileName, $artifact, [
                    'title' => $depTitle,
                    'summary' => ['mode' => 'dependency', 'target_dir' => $targetDir],
                ]);

                $present[] = mb_strtolower($fileName);
                $report[$dependencyId] = 'installed';
            } catch (Throwable $e) {
                $report[$dependencyId] = 'failed: '.mb_substr($e->getMessage(), 0, 160);
            }
        }

        return $report;
    }

    /**
     * Newest primary `.mrpack` bundle of a Modrinth modpack (releases first).
     *
     * Note: loader/game_version filters are deliberately NOT sent for pack queries —
     * the pack itself defines both (inside modrinth.index.json).
     *
     * @return array{url:string, file:string, version:?string, checksum:?string, size:?int}
     */
    private function resolveModrinthMrpack(string $projectId, string $title): array
    {
        foreach (self::releasesFirst($this->modrinth->versions($projectId)) as $version) {
            $chosen = null;

            foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $candidate) {
                $fileName = is_array($candidate) && is_string($candidate['filename'] ?? null) ? $candidate['filename'] : '';

                if (!str_ends_with(mb_strtolower($fileName), '.mrpack') || !is_string($candidate['url'] ?? null) || $candidate['url'] === '') {
                    continue;
                }

                if ($chosen === null || ($candidate['primary'] ?? false) === true) {
                    $chosen = $candidate;
                }
            }

            if ($chosen === null) {
                continue;
            }

            $hashes = is_array($chosen['hashes'] ?? null) ? $chosen['hashes'] : [];

            return [
                'url' => $chosen['url'],
                'file' => $this->sanitizeFileName($chosen['filename'], $title, '.mrpack'),
                'version' => is_string($version['version_number'] ?? null) ? $version['version_number'] : null,
                'checksum' => is_string($hashes['sha1'] ?? null) ? $hashes['sha1'] : null,
                'size' => is_int($chosen['size'] ?? null) ? $chosen['size'] : null,
            ];
        }

        throw $this->fail('download_failed', 'No .mrpack archive is published for this modpack.');
    }

    /**
     * SpigotMC resource through Spiget's download endpoint. Wings follows the redirect
     * chain itself (and refuses internal targets); the panel only checks that the
     * resource is a free, SpigotMC-hosted jar and picks a predictable file name.
     *
     * @return array{url:string, file:string, version:?string, checksum:?string, dependencies:list<string>}
     */
    private function resolveSpigetDownload(string $resourceId, string $title): array
    {
        if (!ctype_digit($resourceId)) {
            throw $this->fail('download_failed', 'That is not a SpigotMC resource id.');
        }

        $resource = $this->spiget->resource($resourceId);

        if ($resource === null) {
            throw $this->fail('download_failed', 'SpigotMC does not know this resource.');
        }

        if (($resource['premium'] ?? false) === true) {
            throw $this->fail('download_failed', 'Premium resources can only be downloaded from spigotmc.org.');
        }

        $fileInfo = is_array($resource['file'] ?? null) ? $resource['file'] : [];
        $type = strtolower(trim((string) ($fileInfo['type'] ?? '.jar')));

        if (($resource['external'] ?? false) === true || $type === 'external') {
            throw $this->fail('download_failed', 'This resource is hosted outside SpigotMC — download it from its own page.');
        }

        if ($type !== '' && $type !== '.jar') {
            throw $this->fail('download_failed', sprintf('SpigotMC serves this resource as a %s file, not a plugin jar.', $type));
        }

        $version = $this->spiget->latestVersionName($resourceId);
        $name = is_string($resource['name'] ?? null) && trim($resource['name']) !== '' ? $resource['name'] : $title;
        $base = Str::slug($name);

        if ($base === '') {
            $base = 'spigot-'.$resourceId;
        }

        return [
            'url' => 'https://api.spiget.org/v2/resources/'.$resourceId.'/download',
            'file' => $this->sanitizeFileName($base.($version !== null ? '-'.$version : '').'.jar', $title),
            'version' => $version,
            'checksum' => null,
            'dependencies' => [],
        ];
    }

    /**
     * Hangar build for the server's platform: a version whose platformDependencies cover
     * the game version beats one that doesn't, a stable channel beats an unstable one,
     * newer beats older. External-only builds (no downloadUrl) are skipped.
     *
     * @param  list<string>  $loaders
     * @return array{url:string, file:string, version:?string, checksum:?string, dependencies:list<string>}
     */
    private function resolveHangarDownload(string $slug, array $loaders, string $mcVersion, string $title): array
    {
        $platform = '';

        foreach ($loaders as $loader) {
            $platform = HangarService::platformFor($loader);

            if ($platform !== '') {
                break;
            }
        }

        if ($platform === '') {
            $platform = 'PAPER';
        }

        $best = null;
        $bestRank = -1;

        foreach ($this->hangar->versions($slug) as $version) {
            $downloads = is_array($version['downloads'] ?? null) ? $version['downloads'] : [];
            $download = is_array($downloads[$platform] ?? null) ? $downloads[$platform] : null;

            if ($download === null || !is_string($download['downloadUrl'] ?? null) || $download['downloadUrl'] === '') {
                continue;
            }

            $platformDependencies = is_array($version['platformDependencies'] ?? null) ? $version['platformDependencies'] : [];
            $supported = is_array($platformDependencies[$platform] ?? null) ? $platformDependencies[$platform] : [];

            $fitsVersion = $mcVersion === '' || $supported === [] || self::anyVersionMatches($supported, $mcVersion);
            $rank = ($fitsVersion ? 2 : 0) + (self::hangarChannelIsStable($version['channel'] ?? null) ? 1 : 0);

            if ($rank > $bestRank) {
                $best = [$version, $download];
                $bestRank = $rank;
            }

            if ($rank === 3) {
                break;
            }
        }

        if ($best === null) {
            throw $this->fail('download_failed', sprintf('Hangar publishes no downloadable %s build of this project.', ucfirst(strtolower($platform))));
        }

        [$version, $download] = $best;
        $fileInfo = is_array($download['fileInfo'] ?? null) ? $download['fileInfo'] : [];

        return [
            'url' => (string) $download['downloadUrl'],
            'file' => $this->sanitizeFileName(is_string($fileInfo['name'] ?? null) ? $fileInfo['name'] : '', $title),
            'version' => is_string($version['name'] ?? null) ? $version['name'] : null,
            'checksum' => is_string($fileInfo['sha256Hash'] ?? null) ? $fileInfo['sha256Hash'] : null,
            'dependencies' => [],
        ];
    }

    /**
     * Newest release-type CurseForge file for the server's loader (game version first,
     * then any version of that loader). CurseForge omits `downloadUrl` unless authors
     * allow third-party downloads; such files are skipped, never rebuilt by hand.
     *
     * @param  list<string>  $loaders
     * @return array{url:string, file:string, version:?string, checksum:?string, dependencies:list<string>}
     */
    private function resolveCurseForgeFile(string $modId, array $loaders, string $mcVersion, string $title): array
    {
        if (!ctype_digit($modId)) {
            throw $this->fail('download_failed', 'That is not a CurseForge project id.');
        }

        if ($this->curseForge->apiKey() === '') {
            throw $this->fail('download_failed', 'CurseForge needs an API key — add it in the plugin settings.');
        }

        $types = [];

        foreach ($loaders as $loader) {
            $type = CurseForgeService::modLoaderTypeFor($loader);

            if ($type !== null && !in_array($type, $types, true)) {
                $types[] = $type;
            }
        }

        $attempts = [];

        if ($types !== []) {
            foreach ($types as $type) {
                if ($mcVersion !== '') {
                    $attempts[] = ['gameVersion' => $mcVersion, 'modLoaderType' => $type];
                }
            }

            foreach ($types as $type) {
                $attempts[] = ['modLoaderType' => $type];
            }
        } else {
            if ($mcVersion !== '') {
                $attempts[] = ['gameVersion' => $mcVersion];
            }

            $attempts[] = [];
        }

        foreach ($attempts as $filters) {
            $file = self::pickCurseForgeFile($this->curseForge->files($modId, $filters));

            if ($file === null) {
                continue;
            }

            $sha1 = null;

            foreach (is_array($file['hashes'] ?? null) ? $file['hashes'] : [] as $hash) {
                if (is_array($hash) && ($hash['algo'] ?? null) === 1 && is_string($hash['value'] ?? null)) {
                    $sha1 = $hash['value'];

                    break;
                }
            }

            $display = is_string($file['displayName'] ?? null) ? trim($file['displayName']) : '';

            return [
                'url' => (string) $file['downloadUrl'],
                'file' => $this->sanitizeFileName(is_string($file['fileName'] ?? null) ? $file['fileName'] : '', $title),
                'version' => $display !== '' ? $display : null,
                'checksum' => $sha1,
                'dependencies' => [],
            ];
        }

        throw $this->fail('download_failed', 'CurseForge offers no direct download for a matching file (the author may have disabled third-party downloads).');
    }

    /**
     * Newest available file with a download link, release builds first.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>|null
     */
    private static function pickCurseForgeFile(array $rows): ?array
    {
        $usable = array_values(array_filter($rows, fn ($file): bool => is_array($file)
            && ($file['isAvailable'] ?? true) !== false
            && is_string($file['downloadUrl'] ?? null)
            && $file['downloadUrl'] !== ''));

        // Larger id == newer upload.
        usort($usable, fn (array $a, array $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));

        foreach ($usable as $file) {
            if ((int) ($file['releaseType'] ?? 0) === 1) {
                return $file;
            }
        }

        return $usable[0] ?? null;
    }

    // -------------------------------------------------------------------------
    // Modpacks
    // -------------------------------------------------------------------------

    /**
     * Full Modrinth `.mrpack` pipeline, staged so a failure leaves the live files untouched:
     *
     *   1. pull the archive (foreground) into a private staging folder and unpack it there;
     *   2. read modrinth.index.json, skip `env.server: unsupported` (client-only) files,
     *      refuse any path that escapes the server folder or any host outside the spec's
     *      allow-list — a pack that breaks those rules fails as a whole;
     *   3. download every file into the staging folder (sequential foreground pulls —
     *      wings allows three at once per server);
     *   4. only then move everything into place: downloads, then overrides/, then
     *      server-overrides/ (later layers win; folders merge, same-named files are
     *      replaced, a folder never replaces a file or the other way round);
     *   5. remove the staging folder in every outcome.
     *
     * Installing the loader itself is the Versions page's job; the pack's requirement is
     * recorded and compared with the detected software.
     */
    private function installModpack(Server $server, string $source, string $projectId, string $title, bool $isUpdate): InstalledContent
    {
        if ($source !== 'modrinth') {
            throw $this->fail('download_failed', 'Modpacks can only be installed from Modrinth (.mrpack).');
        }

        /** @var array{url:string, file:string, version:?string, checksum:?string, size:?int} $pack */
        $pack = $this->fromRegistry(fn (): array => $this->resolveModrinthMrpack($projectId, $title));

        $limit = (int) config('minecraft-toolkit.limits.mrpack_max_bytes', 209715200);

        if ($limit > 0 && $pack['size'] !== null && $pack['size'] > $limit) {
            throw $this->fail('download_failed', sprintf(
                'The modpack archive is %d MiB, above the %d MiB limit.',
                (int) ceil($pack['size'] / 1048576),
                intdiv($limit, 1048576),
            ));
        }

        $previousChildren = $this->packChildRows($server, $projectId);
        $isUpdate = $isUpdate
            || $previousChildren->isNotEmpty()
            || $this->standaloneRows($server, 'modpack', $source, $projectId)->isNotEmpty();

        // Hundreds of sequential downloads outlive PHP's default limit, and a closed browser
        // tab must not abort the run between staging and moving files into place.
        // (Hosts may disable either function; PHP 8 then throws on the call, hence the guards.)
        $canLiftLimit = function_exists('set_time_limit');
        $canIgnoreAbort = function_exists('ignore_user_abort');
        $originalTimeLimit = (int) ini_get('max_execution_time');
        $originalIgnoreAbort = $canIgnoreAbort ? ignore_user_abort(true) : 0;

        if ($canLiftLimit) {
            @set_time_limit(0);
        }

        $staging = '/'.self::STAGING_PREFIX.Str::lower(Str::random(12));

        try {
            $result = $this->assembleModpack($server, $pack['url'], $staging);
        } finally {
            $this->removeStaging($server, $staging);

            if ($canIgnoreAbort) {
                ignore_user_abort((bool) $originalIgnoreAbort);
            }

            if ($canLiftLimit) {
                @set_time_limit($originalTimeLimit);
            }
        }

        $requires = $result['requires'];
        $requires['matches_server'] = $this->packMatchesServer($server, $requires);

        $children = [];

        foreach ($result['files'] as $path) {
            $childKind = $this->childKind($path);

            if ($childKind !== null) {
                $children[] = ['path' => $path, 'kind' => $childKind];
            }
        }

        $summary = [
            'mode' => 'mrpack',
            'archive' => $pack['file'],
            'files_total' => $result['files_total'],
            'files_installed' => count($result['files']),
            'files_client_only_skipped' => $result['client_only'],
            'files_tracked' => count($children),
            'override_layers' => $result['override_layers'],
            'conflicts' => array_slice($result['conflicts'], 0, 25),
            'requires' => $requires,
        ];

        $row = $this->trackRow($server, 'modpack', $source, $projectId, self::MRPACK_INDEX, $pack, [
            'title' => $title,
            'summary' => $summary,
        ]);

        $this->trackChildRows($server, $children, $projectId, $title);

        // Files of the previous version of THIS pack that the new version no longer ships.
        $kept = array_map('mb_strtolower', $result['files']);
        $stale = $previousChildren->reject(
            fn (InstalledContent $child): bool => in_array(mb_strtolower((string) $child->file_name), $kept, true),
        );

        $this->retireReplaced($server, $stale, $row);

        $this->logInstall($server, $isUpdate, $title, $source, $pack['file'], count($result['files']));

        return $row;
    }

    /**
     * Steps 1–4 of {@see self::installModpack()}.
     *
     * @return array{files:list<string>, files_total:int, client_only:int, override_layers:int,
     *               conflicts:list<string>, requires:array{minecraft:?string, loader:?string, loader_version:?string}}
     */
    private function assembleModpack(Server $server, string $archiveUrl, string $staging): array
    {
        $extractDir = $staging.'/pack';
        $downloadDir = $staging.'/downloads';

        // 1. Archive → staging. The explicit .zip name keeps wings' archive detection happy
        //    (it matches on name AND content) whatever the upstream file was called.
        try {
            $this->files->pull($server, $archiveUrl, $extractDir, 'pack.zip');
        } catch (Throwable $e) {
            throw $this->fail('download_failed', $this->nodeReason($e, 'The modpack archive could not be downloaded to the server.'), $e);
        }

        try {
            $this->files->decompress($server, $extractDir, 'pack.zip');
        } catch (Throwable $e) {
            throw $this->fail('unpack_failed', $this->nodeReason($e, 'The modpack archive failed to unpack.'), $e);
        }

        // 2. The index sits at the archive root.
        try {
            $index = $this->files->readJson($server, $extractDir.'/'.self::MRPACK_INDEX);
        } catch (Throwable $e) {
            throw $this->fail('unpack_failed', 'Reading modrinth.index.json failed.', $e);
        }

        if (!is_array($index) || ($index['game'] ?? null) !== 'minecraft' || !is_array($index['files'] ?? null)) {
            throw $this->fail('unpack_failed', 'The archive holds no valid modrinth.index.json.');
        }

        $plan = [];
        $seen = [];
        $clientOnly = 0;

        foreach ($index['files'] as $entry) {
            if (!is_array($entry)) {
                throw $this->fail('unpack_failed', 'The modpack file table is malformed.');
            }

            $env = is_array($entry['env'] ?? null) ? $entry['env'] : [];

            if (($env['server'] ?? null) === 'unsupported') {
                ++$clientOnly;

                continue;
            }

            $path = self::safeRelativePath($entry['path'] ?? null);

            if ($path === null) {
                throw $this->fail('unpack_failed', sprintf(
                    'The modpack lists a file outside the server folder (%s).',
                    mb_substr(is_string($entry['path'] ?? null) ? $entry['path'] : '?', 0, 80),
                ));
            }

            $url = self::allowedPackUrl($entry['downloads'] ?? null);

            if ($url === null) {
                throw $this->fail('download_failed', sprintf('The modpack wants %s from a host outside the Modrinth allow-list.', $path));
            }

            if (isset($seen[$path])) {
                continue;
            }

            $seen[$path] = true;
            $plan[] = ['path' => $path, 'url' => $url];
        }

        // 3. Everything lands in staging first — a failed download leaves the server untouched.
        foreach ($plan as $item) {
            $directory = dirname($item['path']);

            try {
                $this->files->pull(
                    $server,
                    $item['url'],
                    $directory === '.' ? $downloadDir : $downloadDir.'/'.$directory,
                    basename($item['path']),
                );
            } catch (Throwable $e) {
                throw $this->fail('download_failed', sprintf('%s: %s', $item['path'], $this->nodeReason($e, 'the download failed.')), $e);
            }
        }

        // 4. Move into place: downloads, then overrides/, then server-overrides/.
        $conflicts = [];
        $layers = 0;

        try {
            $archiveRoot = [];

            foreach ($this->files->ls($server, $extractDir) as $entry) {
                $archiveRoot[(string) $entry['name']] = $entry['directory'];
            }

            if ($plan !== []) {
                $this->mergeTree($server, $downloadDir, '/', $conflicts);
            }

            foreach (['overrides', 'server-overrides'] as $layer) {
                if (($archiveRoot[$layer] ?? false) === true) {
                    $this->mergeTree($server, $extractDir.'/'.$layer, '/', $conflicts);
                    ++$layers;
                }
            }

            // Keep the pack's index at the server root: it documents the installed pack.
            try {
                $this->files->delete($server, [self::MRPACK_INDEX], '/');
            } catch (Throwable) {
                // Not there yet.
            }

            $this->files->rename($server, $extractDir.'/'.self::MRPACK_INDEX, '/'.self::MRPACK_INDEX);
        } catch (DomainException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw $this->fail('unpack_failed', 'Moving the modpack files into place failed midway — check the server folder before starting it.', $e);
        }

        return [
            'files' => array_column($plan, 'path'),
            'files_total' => count($index['files']),
            'client_only' => $clientOnly,
            'override_layers' => $layers,
            'conflicts' => $conflicts,
            'requires' => self::packRequirements($index),
        ];
    }

    /**
     * Merge one staged folder into a live folder. Missing entries move over in one rename
     * (wings creates parent folders), existing folders are merged recursively, existing
     * files are replaced. A folder never replaces a file nor the reverse — such clashes
     * are reported instead of deleting user data. Wings' rename refuses an existing
     * destination, hence the explicit delete of replaced files first.
     *
     * @param  list<string>  $conflicts
     */
    private function mergeTree(Server $server, string $from, string $to, array &$conflicts, int $depth = 0): void
    {
        if ($depth > self::MAX_MERGE_DEPTH) {
            throw $this->fail('unpack_failed', 'The modpack folder tree is nested too deeply.');
        }

        $incoming = $this->files->ls($server, $from);

        if ($incoming === []) {
            return;
        }

        $existing = [];

        try {
            foreach ($this->files->ls($server, $to) as $entry) {
                $existing[(string) $entry['name']] = $entry['directory'];
            }
        } catch (Throwable) {
            // Destination folder missing: everything moves over wholesale.
        }

        $replace = [];
        $move = [];
        $descend = [];

        foreach ($incoming as $entry) {
            $name = (string) $entry['name'];

            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }

            if (!array_key_exists($name, $existing)) {
                $move[] = $name;
            } elseif ($entry['directory'] && $existing[$name]) {
                $descend[] = $name;
            } elseif (!$entry['directory'] && !$existing[$name]) {
                $replace[] = $name;
                $move[] = $name;
            } else {
                $conflicts[] = ltrim(self::joinPath($to, $name), '/');
            }
        }

        if ($replace !== []) {
            $this->files->delete($server, $replace, $to);
        }

        foreach ($move as $name) {
            $this->files->rename($server, self::joinPath($from, $name), self::joinPath($to, $name));
        }

        foreach ($descend as $name) {
            $this->mergeTree($server, self::joinPath($from, $name), self::joinPath($to, $name), $conflicts, $depth + 1);
        }
    }

    private function removeStaging(Server $server, string $staging): void
    {
        try {
            $this->files->delete($server, [ltrim($staging, '/')], '/');
        } catch (Throwable) {
            // Nothing was staged yet, or the node went away; the dot-folder is inert either way.
        }
    }

    /**
     * Loader + game version the pack was built for (modrinth.index.json `dependencies`).
     *
     * @param  array<string, mixed>  $index
     * @return array{minecraft:?string, loader:?string, loader_version:?string}
     */
    private static function packRequirements(array $index): array
    {
        $dependencies = is_array($index['dependencies'] ?? null) ? $index['dependencies'] : [];
        $loader = null;
        $loaderVersion = null;

        foreach (['fabric-loader' => 'fabric', 'quilt-loader' => 'quilt', 'forge' => 'forge', 'neoforge' => 'neoforge'] as $key => $name) {
            if (is_string($dependencies[$key] ?? null) && $dependencies[$key] !== '') {
                $loader = $name;
                $loaderVersion = $dependencies[$key];

                break;
            }
        }

        return [
            'minecraft' => is_string($dependencies['minecraft'] ?? null) ? $dependencies['minecraft'] : null,
            'loader' => $loader,
            'loader_version' => $loaderVersion,
        ];
    }

    /**
     * Whether the detected software already matches what the pack needs.
     *
     * @param  array{minecraft:?string, loader:?string, loader_version:?string}  $requires
     */
    private function packMatchesServer(Server $server, array $requires): bool
    {
        try {
            $detected = $this->detection->detect($server, true);
        } catch (Throwable) {
            return true; // unknown ⇒ no warning
        }

        $software = strtolower((string) ($detected['software'] ?? 'unknown'));
        $version = is_string($detected['version'] ?? null) ? $detected['version'] : null;

        $loaderOk = $requires['loader'] === null
            || $software === $requires['loader']
            || ($requires['loader'] === 'fabric' && $software === 'quilt');

        $versionOk = $requires['minecraft'] === null || $version === null || $version === $requires['minecraft'];

        return $loaderOk && $versionOk;
    }

    /**
     * Validate one `files[].path` from a pack index: relative, forward slashes, no `..`,
     * no empty or dot segments, never into our own staging folder.
     */
    private static function safeRelativePath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || mb_strlen($path) > 240) {
            return null;
        }

        if (str_contains($path, '\\') || str_contains($path, "\0") || str_starts_with($path, '/') || preg_match('/^[A-Za-z]:/', $path) === 1) {
            return null;
        }

        $segments = explode('/', $path);

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        if (str_starts_with($segments[0], self::STAGING_PREFIX)) {
            return null;
        }

        return implode('/', $segments);
    }

    /**
     * First download URL of a pack entry that is HTTPS on an allow-listed host.
     */
    private static function allowedPackUrl(mixed $downloads): ?string
    {
        foreach (is_array($downloads) ? $downloads : [] as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }

            $parts = parse_url($candidate);

            if (
                is_array($parts)
                && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
                && in_array(strtolower((string) ($parts['host'] ?? '')), self::MRPACK_ALLOWED_HOSTS, true)
                && !isset($parts['user'])
                && !isset($parts['port'])
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /** plugin↔plugins/, mod↔mods/ prefixes; everything else stays untracked (null). */
    private function childKind(string $relativePath): ?string
    {
        $lower = mb_strtolower(ltrim($relativePath, '/'));

        if (!str_ends_with($lower, '.jar')) {
            return null;
        }

        if (str_starts_with($lower, 'plugins/')) {
            return InstalledContent::KIND_PLUGIN;
        }

        if (str_starts_with($lower, 'mods/')) {
            return InstalledContent::KIND_MOD;
        }

        return null;
    }

    /**
     * Persist per-file child rows produced by the mrpack flow (umbrella written separately).
     *
     * @param  list<array{path:string, kind:string}>  $children
     */
    private function trackChildRows(Server $server, array $children, string $projectId, string $packTitle): void
    {
        try {
            DB::transaction(function () use ($server, $children, $projectId, $packTitle): void {
                foreach ($children as $child) {
                    $this->trackRow($server, $child['kind'], 'modrinth', $projectId, $child['path'], [], ['pack' => $packTitle]);
                }
            });
        } catch (Throwable) {
            // Child-row persistence problems never invalidate the delivered files;
            // they simply show up as foreign jars until the next install.
        }
    }

    /**
     * Child rows of one modpack (any kind), as tracked before this run.
     *
     * @return Collection<int, InstalledContent>
     */
    private function packChildRows(Server $server, string $projectId): Collection
    {
        return InstalledContent::query()
            ->where('server_id', $server->getKey())
            ->where('registry', 'modrinth')
            ->where('project_id', $projectId)
            ->whereIn('kind', [InstalledContent::KIND_PLUGIN, InstalledContent::KIND_MOD])
            ->get()
            ->filter(fn (InstalledContent $row): bool => $row->isPackChild())
            ->values();
    }

    // -------------------------------------------------------------------------
    // Disk writing + bookkeeping
    // -------------------------------------------------------------------------

    /**
     * Foreground pull with the exact file name we track; returns the name wings wrote.
     *
     * @param  array<string, mixed>  $artifact
     */
    private function pullArtifact(Server $server, array $artifact, string $directory): string
    {
        $fileName = (string) $artifact['file'];

        try {
            $stat = $this->files->pull($server, (string) $artifact['url'], $directory, $fileName);
        } catch (ConnectionException $e) {
            throw $this->fail('wings_502', 'The node did not answer the download request.', $e);
        } catch (Throwable $e) {
            throw $this->fail('download_failed', $this->nodeReason($e, "The node could not download {$fileName}."), $e);
        }

        $written = is_string($stat['name'] ?? null) ? basename($stat['name']) : '';

        return $written !== '' ? $written : $fileName;
    }

    /**
     * Rows of one standalone project (pack children excluded — they carry the pack's id).
     *
     * @return Collection<int, InstalledContent>
     */
    private function standaloneRows(Server $server, string $kind, string $source, string $projectId): Collection
    {
        return InstalledContent::query()
            ->where('server_id', $server->getKey())
            ->where('kind', $kind)
            ->whereIn('registry', $source === 'spigotmc' ? ['spigotmc', 'spigot'] : [$source])
            ->where('project_id', $projectId)
            ->get()
            ->reject(fn (InstalledContent $row): bool => $row->isPackChild())
            ->values();
    }

    /**
     * Delete the files (and rows) of superseded installs, keeping the freshly written one.
     *
     * @param  Collection<int, InstalledContent>  $rows
     */
    private function retireReplaced(Server $server, Collection $rows, InstalledContent $current): void
    {
        $dirs = $this->contentDirs($server);
        $currentPath = mb_strtolower((string) $this->pathOfRow($current, $dirs));

        foreach ($rows as $old) {
            if ($old->getKey() === $current->getKey()) {
                continue;
            }

            $path = $this->pathOfRow($old, $dirs);

            // Same file under another spelling (case-variant row): drop the stale row only.
            if ($path === null || mb_strtolower($path) === $currentPath) {
                $old->delete();

                continue;
            }

            $directory = dirname($path);
            $wanted = mb_strtolower(basename($path));

            try {
                $actual = null;

                foreach ($this->files->ls($server, $directory) as $entry) {
                    if (!$entry['directory'] && mb_strtolower((string) $entry['name']) === $wanted) {
                        $actual = (string) $entry['name'];

                        break;
                    }
                }

                if ($actual !== null) {
                    $this->files->delete($server, [$actual], $directory);
                }

                $old->delete();
            } catch (Throwable) {
                // Node trouble: keep the row so the leftover file stays visible on the page.
            }
        }
    }

    /**
     * Insert or refresh one tracking row (unique per server + file name).
     *
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $dependencies  JSON payload: title + summary, or the pack marker
     */
    private function trackRow(Server $server, string $kind, string $source, string $projectId, string $fileName, array $artifact, array $dependencies): InstalledContent
    {
        return DB::transaction(function () use ($server, $kind, $source, $projectId, $fileName, $artifact, $dependencies): InstalledContent {
            $row = InstalledContent::query()
                ->where('server_id', $server->getKey())
                ->where('file_name', $fileName)
                ->first() ?? new InstalledContent;

            $checksum = $artifact['checksum'] ?? null;

            $row->server_id = $server->getKey();
            $row->kind = $kind;
            $row->registry = $source;
            $row->project_id = mb_substr($projectId, 0, 64);
            $row->file_name = $fileName;
            $row->version = self::fitColumn($artifact['version'] ?? null);
            $row->checksum = is_string($checksum) && $checksum !== '' && strlen($checksum) <= 64 ? $checksum : null;
            $row->dependencies = $dependencies;
            $row->tracked_by_toolkit = true;
            // A reinstall of the very same build changes no column; bump the timestamp anyway so
            // the update indicator (newest upstream release vs. updated_at) clears once acted on.
            $row->updated_at = $row->freshTimestamp();

            $row->save();

            return $row;
        });
    }

    /** Activity entry whose properties match the lang/en/activity.php placeholders. */
    private function logInstall(Server $server, bool $isUpdate, string $title, string $source, string $file, int $fileCount): void
    {
        try {
            $log = Activity::event($isUpdate ? 'mc-toolkit:content.update' : 'mc-toolkit:content.install')
                ->subject($server)
                ->property('title', $title)
                ->property('source', self::SOURCE_LABELS[$source] ?? $source)
                ->property('file', $file);

            if ($fileCount > 0) {
                $log->property('files_installed', $fileCount);
            }

            $log->log();
        } catch (Throwable) {
            // Observability flakiness never rolls back an already-successful install.
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Content directories wired from detection (absolute), falling back to vanilla defaults.
     *
     * @return array{plugins:string, mods:string}
     */
    private function contentDirs(Server $server): array
    {
        try {
            $detected = $this->detection->detect($server);
        } catch (Throwable) {
            $detected = null;
        }

        $dirs = is_array($detected['content_dirs'] ?? null) ? $detected['content_dirs'] : [];

        $plugins = is_string($dirs['plugins'] ?? null) ? trim($dirs['plugins'], '/ ') : '';
        $mods = is_string($dirs['mods'] ?? null) ? trim($dirs['mods'], '/ ') : '';

        return [
            'plugins' => '/'.($plugins !== '' ? $plugins : 'plugins'),
            'mods' => '/'.($mods !== '' ? $mods : 'mods'),
        ];
    }

    private function targetDir(Server $server, string $kind): string
    {
        $dirs = $this->contentDirs($server);

        return $kind === InstalledContent::KIND_PLUGIN ? $dirs['plugins'] : $dirs['mods'];
    }

    /**
     * Absolute path of a row's file: bare names live in the folder of their kind,
     * names with a slash are relative to the server root. Null for unusable names.
     *
     * @param  array{plugins:string, mods:string}  $dirs
     */
    private function pathOfRow(InstalledContent $row, array $dirs): ?string
    {
        $raw = trim(str_replace('\\', '/', (string) $row->file_name), '/');

        if ($raw === '' || $raw === '.' || str_contains('/'.$raw.'/', '/../')) {
            return null;
        }

        if (str_contains($raw, '/')) {
            return '/'.$raw;
        }

        return match ((string) $row->kind) {
            InstalledContent::KIND_PLUGIN => rtrim($dirs['plugins'], '/').'/'.$raw,
            InstalledContent::KIND_MOD => rtrim($dirs['mods'], '/').'/'.$raw,
            default => '/'.$raw,
        };
    }

    /** Lowercase names of every jar currently inside one directory. */
    private function scanNames(Server $server, string $dir): array
    {
        $names = [];

        foreach ($this->listJars($server, $dir) as $entry) {
            $name = is_string($entry['name'] ?? null) ? mb_strtolower($entry['name']) : null;

            if ($name !== null && $name !== '') {
                $names[$name] = true;
            }
        }

        return $names;
    }

    private function listJars(Server $server, string $dir): array
    {
        try {
            $entries = $this->files->ls($server, $dir);
        } catch (Throwable) {
            return [];
        }

        return array_values(array_filter($entries, function ($entry) {
            return is_array($entry)
                && is_string($entry['name'] ?? null)
                && !($entry['directory'] ?? false)
                && (bool) preg_match('/\.jar$/i', $entry['name']);
        }));
    }

    /**
     * Whether a folder listing already holds a jar of one project ("<slug>.jar",
     * "<slug>-1.2.jar", "<slug>_1.2.jar").
     *
     * @param  list<string>  $names  lowercase file names
     */
    private static function listingHasProject(array $names, string $slug): bool
    {
        foreach ($names as $name) {
            if ($name === $slug.'.jar' || str_starts_with($name, $slug.'-') || str_starts_with($name, $slug.'_')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Modrinth versions with release builds first (beta/alpha only when nothing else exists),
     * each group keeping upstream newest-first order.
     *
     * @param  list<array<string, mixed>>  $versions
     * @return list<array<string, mixed>>
     */
    private static function releasesFirst(array $versions): array
    {
        $release = [];
        $other = [];

        foreach ($versions as $version) {
            if (($version['version_type'] ?? 'release') === 'release') {
                $release[] = $version;
            } else {
                $other[] = $version;
            }
        }

        return [...$release, ...$other];
    }

    /**
     * First primary file declaration of one Modrinth version payload.
     *
     * @param  array<string, mixed>  $version
     * @return array<string, mixed>|null
     */
    private function primaryModrinthFile(array $version): ?array
    {
        $primary = null;
        $first = null;

        foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $candidate) {
            if (!is_array($candidate)) {
                continue;
            }

            $first ??= $candidate;

            if (($candidate['primary'] ?? false) === true) {
                $primary = $candidate;

                break;
            }
        }

        return $primary ?? $first;
    }

    /**
     * @param  list<mixed>  $versions
     */
    private static function anyVersionMatches(array $versions, string $mcVersion): bool
    {
        foreach ($versions as $version) {
            if (is_string($version) && ContentSearchService::gameVersionMatches($version, $mcVersion)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Hangar channels carry no stability enum; the UNSTABLE flag or a pre-release name marks them.
     */
    private static function hangarChannelIsStable(mixed $channel): bool
    {
        if (!is_array($channel)) {
            return true;
        }

        $flags = is_array($channel['flags'] ?? null) ? array_filter($channel['flags'], fn ($flag) => is_string($flag)) : [];

        if (in_array('UNSTABLE', $flags, true)) {
            return false;
        }

        $name = strtolower(is_string($channel['name'] ?? null) ? $channel['name'] : '');

        return preg_match('/snapshot|alpha|beta|dev|nightly|unstable|experimental|pre/', $name) !== 1;
    }

    private function lookupTitle(string $source, string $projectId): ?string
    {
        try {
            $title = match ($source) {
                'modrinth' => $this->modrinth->project($projectId)['title'] ?? null,
                'spigotmc' => ctype_digit($projectId) ? ($this->spiget->resource($projectId)['name'] ?? null) : null,
                default => null,
            };
        } catch (Throwable) {
            return null;
        }

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /**
     * Plugins and mods are jars; anything else (zips, skripts, datapacks) would sit in the
     * folder unloaded, so it is refused with a clear message instead.
     */
    private function assertJar(string $fileName): void
    {
        if (str_ends_with(mb_strtolower($fileName), '.mrpack')) {
            throw $this->fail('download_failed', 'This project is a modpack — install it from the Modpacks page.');
        }

        if (!str_ends_with(mb_strtolower($fileName), '.jar')) {
            throw $this->fail('download_failed', sprintf('The newest matching file (%s) is not a .jar the server can load.', $fileName));
        }
    }

    /** Readable reason from a failed wings call (its `{error}` body), else the fallback. */
    private function nodeReason(Throwable $e, string $fallback): string
    {
        if ($e instanceof RequestException) {
            $message = $e->response->json('error');

            if (is_string($message) && trim($message) !== '') {
                return mb_substr(trim($message), 0, 200);
            }
        }

        if ($e instanceof DomainException) {
            return $e->getMessage();
        }

        return $fallback;
    }

    private static function joinPath(string $directory, string $name): string
    {
        return rtrim($directory, '/').'/'.$name;
    }

    private static function fitColumn(mixed $value, int $max = 64): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        return mb_substr(trim($value), 0, $max);
    }

    /**
     * Filesystem-safe file name: path components stripped, unusual characters folded to '-',
     * length bounded, `$extension` appended when the candidate is empty.
     */
    private function sanitizeFileName(string $candidate, string $fallbackTitle, string $extension = '.jar'): string
    {
        $clean = basename(str_replace('\\', '/', trim($candidate)));

        if ($clean === '' || $clean === '.' || $clean === '..') {
            $slug = Str::slug($fallbackTitle);
            $clean = ($slug !== '' ? $slug : 'toolkit-artifact').$extension;
        }

        $clean = preg_replace('/[^A-Za-z0-9._+\-]+/', '-', $clean) ?? $clean;
        $clean = trim($clean, '-.');

        if (strlen($clean) > 180) {
            $ext = pathinfo($clean, PATHINFO_EXTENSION);
            $clean = rtrim(substr($clean, 0, 170), '-.').($ext !== '' ? '.'.$ext : '');
        }

        return $clean !== '' ? $clean : 'toolkit-artifact'.$extension;
    }

    /**
     * Friendly, translation-backed domain errors; contextual fragments ride as suffixes.
     */
    private function fail(string $statusKey, string $contextHint = '', ?Throwable $previous = null): DomainException
    {
        $message = trans("minecraft-toolkit::errors.statuses.{$statusKey}");

        if (!is_string($message) || $message === '') {
            $message = "minecraft-toolkit::errors.statuses.{$statusKey}";
        }

        if ($contextHint !== '') {
            $message .= ' '.$contextHint;
        }

        return new DomainException($message, 0, $previous);
    }
}
