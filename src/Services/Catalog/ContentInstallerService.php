<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use App\Enums\ContainerStatus;
use App\Facades\Activity;
use App\Models\Server;
use DomainException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\Registries\CurseForgeService;
use McToolkit\Services\Catalog\Registries\HangarService;
use McToolkit\Services\Catalog\Registries\ModrinthService;
use McToolkit\Services\CommandBus;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Support\HttpJsonCache;
use Throwable;

/**
 * Installs plugins / mods / modpacks onto a server through the panel's file bridge.
 *
 * Owner: A3. Public API frozen by the contract:
 *
 *   installProject(Server $server, string $kind, array $project, array $versionMeta): InstalledContent
 *   installed(Server $server, string $kind): array           // ['tracked'=>…, 'foreign'=>names+sizes]
 *   forgetMissing(Server $server): void                      // parity with addon v1.1.8
 *
 * Behavioural rules (contract §installer + wave brief):
 *  - STOP-SAFE gate: never touch the disk while a wing-managed install script runs
 *    (`ServerState::Installing`). Single jars may land while the server runs (warn-level);
 *    modpack installs additionally REQUIRE a fully stopped container (Offline/Exited/Created),
 *    enforced through CommandBus::canSend() inverted semantics (true == running == forbidden here).
 *  - Services never notify: friendly DomainExceptions whose messages come from
 *    trans('minecraft-toolkit::errors.statuses.*') — pages own notifications.
 *  - Every successful mutation logs Activity event mc-toolkit:content.install with
 *    `project` + `file` properties (labels mirrored in lang/en/activity.php).
 *
 * FileBridge cooperation detail: `FileBridge::pull()` carries NO explicit file-name param,
 * so the on-disk truth is discovered by diffing the target directory listing before/after
 * the pull; the freshly appeared jar name becomes the tracked row's file_name. When the
 * pull replaces an existing jar (update flow, empty diff) the sanitized artifact name is used.
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

    /** Hangar platform disambiguation order when the loader hint is unspecified. */
    private const HANGAR_PLATFORM_PREFERENCE = ['PAPER', 'VELOCITY', 'FOLIA', 'WATERFALL', 'BUNGEECORD'];

    /**
     * CurseForge documented modLoaderType ids (CurseAPI v1 modLoaderTypes enum),
     * used to narrow /files queries when the detection loader hint resolves.
     */
    private const CF_MOD_LOADER_TYPES = [
        'forge' => 1,
        'fabric' => 4,
        'quilt' => 5,
        'neoforge' => 6,
    ];

    public function __construct(
        private readonly FileBridge $files,
        private readonly CommandBus $commands,
        private readonly DetectionService $detection,
        private readonly ModrinthService $modrinth,
        private readonly HangarService $hangar,
        private readonly CurseForgeService $curseForge,
    ) {}

    /**
     * Install ONE best-matching artifact of `$project` for this server.
     *
     * @param  string  $kind  plugin|mod|modpack (plurals tolerated)
     * @param  array<string, mixed>  $project  uniform catalog item (ContentSearchService shape);
     *               required keys: source, id, title
     * @param  array<string, mixed>  $versionMeta  constraint bag:
     *               ['loader' => 'paper', 'mc_version' => '1.21.3'] — gracefully ignored when
     *               nothing matches (latest plain version installs instead)
     * @throws DomainException translation-backed whenever anything blocks or fails
     */
    public function installProject(Server $server, string $kind, array $project, array $versionMeta = []): InstalledContent
    {
        $kindKey = strtolower(trim($kind));
        $kind = self::KIND_ALIASES[$kindKey] ?? throw new \InvalidArgumentException("Unsupported content kind [{$kind}].");

        $source = strtolower((string) ($project['source'] ?? ''));
        $projectId = (string) ($project['id'] ?? '');
        $title = trim((string) ($project['title'] ?? 'project'));

        throw_if($projectId === '', new \InvalidArgumentException('The project item carries no usable id.'));

        $this->assertInstallSafe($server, $requireStopped = $kind === 'modpack');

        $artifact = match ($source) {
            'modrinth' => $kind === 'modpack'
                ? $this->resolveModrinthMrpack($projectId)
                : $this->resolveModrinthFile($projectId, $versionMeta, $project),
            'spigotmc' => $this->resolveSpigetDownload($projectId, $title),
            'hangar' => $this->resolveHangarDownload((string) $project['id'], $versionMeta),
            'curseforge' => $this->resolveCurseForgeFile($projectId, $versionMeta),
            default => throw $this->fail('download_failed', "Source [{$source}] cannot install."),
        };

        [$fileName, $childRowCount, $summary] = $this->writeArtifact($server, $kind, $artifact);

        // Mods page parity promise ("with their dependencies"): pull every REQUIRED
        // Modrinth dependency of the chosen build before tracking the umbrella row
        // (recursion capped at one level so dependency graphs stay linear).
        $dependencyCount = $this->writeRequiredDependencies($server, $kind, $source, $artifact, $versionMeta);

        if ($dependencyCount > 0) {
            $summary['required_dependencies'] = count(is_array($artifact['dependencies'] ?? null) ? $artifact['dependencies'] : []);
            $summary['dependencies_written'] = $dependencyCount;
        }

        $row = $this->trackUmbrellaRow($server, $kind, $source, $projectId, $title, $fileName, $summary, $artifact);

        if ($kind === 'modpack') {
            $this->trackChildRows($server, $summary['children'] ?? [], $source, $projectId, $title);
        }

        $this->logInstall($server, $title, $fileName, $childRowCount);

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
            $dirs = $this->contentDirs($server);
            $scanDir = $kind === 'plugin' ? $dirs['plugins'] : $dirs['mods'];

            foreach ($this->listJars($server, $scanDir) as $entry) {
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

        // Group row ids by the directory their file is expected in.
        $groups = []; // ['<dir>' => ['<basename-lower>' => key]]

        foreach ($rows as $row) {
            $raw = str_replace('\\', '/', (string) $row->file_name);
            $base = basename($raw);

            if ($base === '' || $base === '.') {
                continue;
            }

            $directory = dirname(Str::startsWith($raw, '/') ? $raw : '/'.$raw);
            $relativeDir = ltrim($directory, '/');

            $groups[$relativeDir === '' || $relativeDir === '.' ? '.' : $relativeDir][mb_strtolower($base)] = $row->getKey();
        }

        $toForget = [];

        foreach ($groups as $directory => $basenames) {
            try {
                $entries = $this->files->ls($server, $directory);
            } catch (Throwable) {
                continue;
            }

            $present = [];

            foreach ($entries as $entry) {
                if (is_array($entry) && is_string($entry['name'] ?? null)) {
                    $present[mb_strtolower($entry['name'])] = true;
                }
            }

            foreach ($basenames as $baseLower => $key) {
                if (!isset($present[$baseLower])) {
                    $toForget[] = $key;
                }
            }
        }

        if ($toForget !== []) {
            InstalledContent::query()->whereIn((new InstalledContent)->getKeyName(), $toForget)->delete();
        }
    }

    // -------------------------------------------------------------------------
    // Safety gate
    // -------------------------------------------------------------------------

    /**
     * Blocking rules:
     *  - stored ServerState=installing        → always blocked (wing script owns FS now)
     *  - transitioning container states       → always blocked
     *  - running containers                   → allowed for single jars, blocked for modpacks.
     *    The modpack stop requirement rides CommandBus::canSend (documented lever):
     *    a starting-or-running box reads true THERE, meaning forbidden HERE.
     *
     * @throws DomainException
     */
    private function assertInstallSafe(Server $server, bool $requireStopped): void
    {
        $storedState = $server->status;

        if ($storedState !== null && $storedState->value === 'installing') {
            throw $this->fail('stopped_required', 'A managed install script owns the filesystem right now.');
        }

        try {
            $live = $server->retrieveStatus();
        } catch (ConnectionException $e) {
            throw $this->fail('wings_502', 'Status could not be checked.', $e);
        } catch (Throwable $e) {
            throw $this->fail('wings_502', 'Status could not be checked.', $e);
        }

        if (in_array($live, self::TRANSITIONING, true)) {
            throw $this->fail('stopped_required', sprintf('The container is %s.', $live->value));
        }

        if (!$requireStopped) {
            return;
        }

        // Inverted semantic lever (see docblock): canSend() === running-ish.
        $runningPerCommandBus = false;

        try {
            $runningPerCommandBus = $this->commands->canSend($server);
        } catch (Throwable) {
            // Fall through to the enum comparison below; never block hard on tool hiccups.
        }

        $stopped = in_array($live, self::STOPPED_OK, true) && !$runningPerCommandBus;

        throw_unless($stopped, $this->fail('stopped_required'));
    }

    // -------------------------------------------------------------------------
    // Artifact resolution
    // -------------------------------------------------------------------------

    /**
     * Best matching non-modpack Modrinth file honoring loader/mc-version hints.
     *
     * When `$depth` is 0 the returned artifact additionally carries the
     * `dependencies` list (REQUIRED dependencies with a project_id of this build)
     * so {@see self::writeRequiredDependencies()} can replay them one level deep.
     *
     * @param  array<string, mixed>  $versionMeta
     * @param  array<string, mixed>  $project
     * @return array{url:string, file:string, version:?string, checksum:?string, dependencies:list<mixed>}
     */
    private function resolveModrinthFile(string $projectId, array $versionMeta, array $project, int $depth = 0): array
    {
        $versions = $this->requestModrinthVersions($projectId, $versionMeta);

        foreach ($versions as $version) {
            if (!is_array($version)) {
                continue;
            }

            $file = $this->primaryModrinthFile($version);

            if ($file !== null) {
                $hashes = is_array($file['hashes'] ?? null) ? $file['hashes'] : [];
                $requiredDeps = [];

                if ($depth === 0 && is_array($version['dependencies'] ?? null)) {
                    foreach ($version['dependencies'] as $dependency) {
                        if (
                            is_array($dependency)
                            && ($dependency['dependency_type'] ?? '') === 'required'
                            && !empty($dependency['project_id'])
                        ) {
                            $requiredDeps[] = (string) $dependency['project_id'];
                        }
                    }
                }

                return [
                    'url' => (string) $file['url'],
                    'file' => $this->sanitizeFileName(is_string($file['filename'] ?? null) ? $file['filename'] : '', (string) ($project['title'] ?? '')),
                    'version' => is_string($version['version_number'] ?? null) ? $version['version_number'] : null,
                    'checksum' => is_string($hashes['sha1'] ?? null) ? $hashes['sha1'] : null,
                    'dependencies' => $requiredDeps,
                ];
            }
        }

        throw $this->fail('download_failed', 'No published build matches this server yet.');
    }

    /**
     * One-level-deep pull of every REQUIRED Modrinth dependency named on the chosen
     * non-modpack build. Dep artifacts land in the same target directory and each is
     * tracked under its own project_id, which keeps them update-dottable by the
     * project-keyed UpdateScanner scan. Resolution/pull failures ride the existing
     * friendly DomainException paths unchanged.
     *
     * @param  array<string, mixed>  $artifact
     * @param  array<string, mixed>  $versionMeta
     */
    private function writeRequiredDependencies(Server $server, string $kind, string $source, array $artifact, array $versionMeta): int
    {
        $depIds = is_array($artifact['dependencies'] ?? null) ? $artifact['dependencies'] : [];

        if ($source !== 'modrinth' || $kind === 'modpack' || $depIds === []) {
            return 0;
        }

        $written = 0;

        foreach ($depIds as $depId) {
            if (!is_string($depId) || trim($depId) === '') {
                continue;
            }

            $depArtifact = $this->resolveModrinthFile(
                $depId,
                $versionMeta,
                ['source' => 'modrinth', 'id' => $depId, 'title' => $depId],
                depth: 1,
            );

            [, , $depSummary] = $this->writeArtifact($server, $kind, $depArtifact);

            $this->trackUmbrellaRow(
                $server,
                $kind,
                'modrinth',
                $depId,
                $depId,
                strval(is_string($depSummary['on_disk'] ?? null) && $depSummary['on_disk'] !== '' ? $depSummary['on_disk'] : $depArtifact['file']),
                $depSummary,
                $depArtifact,
            );

            ++$written;
        }

        return $written;
    }

    /**
     * Newest primary `.mrpack` bundle of a Modrinth modpack.
     *
     * Note: loader/game_version filters are deliberately NOT sent for pack queries —
     * mrpack releases rarely carry loader metadata themselves (it lives inside
     * modrinth.index.json); filtering upstream frequently empties results.
     *
     * @return array{url:string, file:string, version:?string, checksum:?string}
     */
    private function resolveModrinthMrpack(string $projectId): array
    {
        foreach ($this->modrinth->versions($projectId) as $version) {
            if (!is_array($version)) {
                continue;
            }

            foreach (is_array($version['files'] ?? null) ? $version['files'] : [] as $candidate) {
                if (!is_array($candidate)) {
                    continue;
                }

                $fileName = is_string($candidate['filename'] ?? null) ? $candidate['filename'] : '';

                if (!Str::endsWith(mb_strtolower($fileName), '.mrpack')) {
                    continue;
                }

                $hashes = is_array($candidate['hashes'] ?? null) ? $candidate['hashes'] : [];
                $versionNumber = is_string($version['version_number'] ?? null) ? $version['version_number'] : null;

                return [
                    'url' => (string) $candidate['url'],
                    'file' => $fileName !== '' ? $this->sanitizeFileName($fileName, 'modpack') : 'toolkit-modpack.mrpack',
                    'version' => $versionNumber,
                    'checksum' => is_string($hashes['sha1'] ?? null) ? $hashes['sha1'] : null,
                ];
            }
        }

        throw $this->fail('download_failed', 'No .mrpack archive is published for this modpack.');
    }

    /**
     * Walk the Spiget download redirect chain manually and hand back the terminal URL
     * (wings pulls raw URLs; a bare 302 hop chain cannot be followed transparently there).
     *
     * @return array{url:string, file:string, version:?string, checksum:?string}
     */
    private function resolveSpigetDownload(string $resourceId, string $title): array
    {
        $currentUrl = 'https://api.spiget.org/v2/resources/'.rawurlencode($resourceId).'/download';
        $schemeHost = '';

        try {
            for ($hop = 0; $hop < 4; ++$hop) {
                $response = Http::withoutRedirecting()
                    ->timeout(10)
                    ->connectTimeout(5)
                    ->retries(1, 100)
                    ->withOptions(['allow_redirects' => false])
                    ->get($currentUrl);

                $response->throw();

                $status = $response->status();

                if (in_array($status, [301, 302, 303, 307, 308], true)) {
                    $location = $response->header('Location');

                    if ($location === '') {
                        break;
                    }

                    $effective = (string) ($response->effectiveUri() ?? $currentUrl);
                    $schemeHost = self::originOf($effective);

                    $next = self::absolutizeRedirect($location, $schemeHost ?: self::originOf($currentUrl));

                    if ($next === $currentUrl) {
                        break;
                    }

                    $currentUrl = $next;

                    continue;
                }

                if ($status >= 400 && $status < 500 && $status !== 429) {
                    throw $this->fail('download_failed', "SpigotMC answered {$status}.");
                }

                if ($status >= 500 || $status === 429) {
                    throw $this->fail($status === 429 ? 'rate_limited' : 'download_failed');
                }

                break; // success or otherwise-terminal status; accept URL as-is
            }
        } catch (DomainException $e) {
            throw $e;
        } catch (RequestException $e) {
            throw $this->fail('download_failed', 'SpigotMC refused the download.', $e);
        } catch (ConnectionException $e) {
            throw $this->fail('wings_502', 'SpigotMC is unreachable.', $e);
        }

        $disposition = '';
        try {
            $disposition = $response?->header('Content-Disposition') ?? '';
        } catch (Throwable) {
            $disposition = '';
        }

        preg_match('/filename="?([^";]+)"?/i', $disposition, $matches);
        $hintedName = $matches[1] ?? '';

        return [
            'url' => $currentUrl,
            'file' => $this->sanitizeFileName($hintedName, $title) ?: ('spigot-'.$resourceId.'.jar'),
            'version' => null,
            'checksum' => null,
        ];
    }

    /**
     * Latest STABLE-channel platform build of a Hangar project matching the loader
     * family, degrading to any-platform builds rather than failing outright.
     *
     * @param  array<string, mixed>  $versionMeta
     * @return array{url:string, file:string, version:?string, checksum:?string}
     */
    private function resolveHangarDownload(string $ownerSlug, array $versionMeta): array
    {
        $loader = strtoupper(trim((string) ($versionMeta['loader'] ?? '')));

        if ($loader === '' || $loader === 'UNKNOWN') {
            $loader = 'PAPER';
        }

        $best = null;
        $fallback = null;

        foreach ($this->hangar->versions($ownerSlug) as $version) {
            if (!is_array($version)) {
                continue;
            }

            foreach (is_array($version['platforms'] ?? null) ? $version['platforms'] : [] as $platformName => $node) {
                if (!is_array($node) || !is_string($platformName)) {
                    continue;
                }

                if (!in_array($platformName, self::HANGAR_PLATFORM_PREFERENCE, true)) {
                    continue;
                }

                $stability = strtoupper((string) (($node['channel']['stability'] ?? '')));

                $download = null;

                foreach (is_array($node['downloads'] ?? null) ? $node['downloads'] : [] as $dl) {
                    if (is_array($dl) && is_string($dl['downloadUrl'] ?? null) && $dl['downloadUrl'] !== '') {
                        $download = $dl;

                        break;
                    }
                }

                if ($download === null) {
                    continue;
                }

                $candidate = [
                    'url' => (string) $download['downloadUrl'],
                    'file' => $this->sanitizeFileName((string) ($download['fileName'] ?? ''), $ownerSlug),
                    'version' => is_string($version['name'] ?? null) ? $version['name'] : null,
                    'checksum' => is_string($download['sha256'] ?? null) ? $download['sha256'] : null,
                ];

                $fallback ??= $candidate;

                if ($best === null && $stability === 'STABLE' && self::platformMatches($platformName, $loader)) {
                    $best = $candidate;
                }
            }
        }

        return $best ?? $fallback ?? throw $this->fail('download_failed', 'Hangar publishes no downloadable channel here.');
    }

    /**
     * Newest release-type (1) CurseForge file, narrowed by mc version / loader when hinted.
     *
     * CurseForge omits `downloadUrl` unless authors opted in; missing URLs degrade the
     * candidate pool until an empty pool raises the friendly download_failed message.
     *
     * @param  array<string, mixed>  $versionMeta
     * @return array{url:string, file:string, version:?string, checksum:?string}
     */
    private function resolveCurseForgeFile(string $modId, array $versionMeta): array
    {
        $gameVersion = trim((string) ($versionMeta['mc_version'] ?? ''));
        $loader = strtolower(trim((string) ($versionMeta['loader'] ?? '')));

        $query = ['pageSize' => 50];

        if ($gameVersion !== '') {
            $query['gameVersion'] = $gameVersion;
        }

        if (isset(self::CF_MOD_LOADER_TYPES[$loader])) {
            $query['modLoaderType'] = self::CF_MOD_LOADER_TYPES[$loader];
        }

        $payload = HttpJsonCache::get(
            'https://api.curseforge.com/v1/mods/'.rawurlencode($modId).'/files',
            120,
            $query,
            ['x-api-key' => $this->curseForge->apiKey()],
        );

        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        usort($rows, fn ($a, $b): int => (int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0)); // larger id == newer upload

        $releasesOnly = array_values(array_filter($rows, fn ($f) => is_array($f) && (int) ($f['releaseType'] ?? 99) === 1));

        foreach ([$releasesOnly, $rows] as $pool) {
            foreach ($pool as $file) {
                if (!is_array($file)) {
                    continue;
                }

                $url = is_string($file['downloadUrl'] ?? null) ? $file['downloadUrl'] : null;

                if ($url === null || $url === '') {
                    continue;
                }

                return [
                    'url' => $url,
                    'file' => $this->sanitizeFileName(
                        is_string($file['displayName'] ?? null) ? $file['displayName'] : '',
                        $modId,
                    ),
                    'version' => is_string($file['displayName'] ?? null) ? $file['displayName'] : null,
                    'checksum' => is_int($file['fileFingerprint'] ?? null)
                        ? dechex($file['fileFingerprint'])
                        : (is_string($file['fileFingerprint'] ?? null) ? $file['fileFingerprint'] : null),
                ];
            }
        }

        throw $this->fail('download_failed', 'CurseForge exposes no direct link for these files.');
    }

    // -------------------------------------------------------------------------
    // Disk writing
    // -------------------------------------------------------------------------

    /**
     * Materialize ONE artifact via wings; the resulting on-disk name wins over the
     * declared one when the directory listing shows a fresh appearance.
     *
     * @param  array<string, mixed>  $artifact
     * @return array{0:string, 1:int, 2:array<string, mixed>} [diskFileName|null, childRowsWritten, summary]
     */
    private function writeArtifact(Server $server, string $kind, array $artifact): array
    {
        if (Str::endsWith(mb_strtolower((string) $artifact['file']), '.mrpack')) {
            return $this->installModpackBundle($server, $artifact);
        }

        $dirs = $this->contentDirs($server);
        $targetDir = '/'.ltrim($kind === 'plugin' ? $dirs['plugins'] : $dirs['mods'], '/');

        $this->ensureDirectory($server, $targetDir);

        $before = $this->scanNames($server, $targetDir);

        try {
            $this->files->pull($server, (string) $artifact['url'], $targetDir);
        } catch (RequestException $e) {
            throw $this->fail('download_failed', 'Wings rejected the remote pull.', $e);
        } catch (ConnectionException $e) {
            throw $this->fail('wings_502', 'Wings did not answer the pull request.', $e);
        } catch (Throwable $e) {
            throw $this->fail('download_failed', 'The remote pull failed.', $e);
        }

        $after = $this->scanNames($server, $targetDir);

        $fresh = array_values(array_diff(array_keys($after), array_keys($before)));
        sort($fresh, SORT_STRING);

        $diskName = match (true) {
            count($fresh) === 1 => $fresh[0],
            count($fresh) > 1 => mb_strtolower((string) $artifact['file']) === '' ? ($fresh[0] ?? '') : '',
            default => '',
        };

        if ($diskName === '') {
            $diskName = (string) $artifact['file'];
        }

        $summary = [
            'mode' => 'single',
            'target_dir' => $targetDir,
            'declared_file' => (string) $artifact['file'],
            'on_disk' => $diskName,
            'children' => [],
        ];

        return [$diskName, 0, $summary];
    }

    /**
     * Full Modrinth `.mrpack` pipeline (contract-mandated sequence):
     *   pull bundle → wings decompress → locate extracted folder (pre/post root diff)
     *   → read modrinth.index.json → replay each downloads[] entry into its path.
     *
     * Child bookkeeping decision (documented handoff deviation): plugins/* rows are
     * recorded kind=plugin, mods/* kind=mod (both tracked_by_toolkit=true so Updates /
     * Installed tabs see them). Everything else (configs/, shaders/, ...) is counted in
     * the summary below but NOT persisted — the DB `kind` column contract enumerates
     * plugin|mod|modpack|world_map|bridge only.
     *
     * Partial failures never abort the run: each written entry lands individually and
     * failures collect into the returned summary (capped list, reason texts truncated).
     *
     * @return array{0:string, 1:int, 2:array<string, mixed>}
     */
    private function installModpackBundle(Server $server, array $artifact): array
    {
        $bundle = (string) $artifact['file'];

        $beforeRoot = $this->rootNames($server);

        try {
            $this->files->pull($server, (string) $artifact['url'], '/');
        } catch (Throwable $e) {
            throw $this->fail('download_failed', 'The modpack bundle could not be pulled to the server.', $e);
        }

        try {
            $this->files->decompress($server, '/', $bundle);
        } catch (Throwable $e) {
            throw $this->fail('unpack_failed', 'The modpack archive failed to unpack.', $e);
        }

        $extractedFolder = $this->freshFolder($beforeRoot, $this->rootNames($server));

        if ($extractedFolder === null) {
            throw $this->fail('unpack_failed', 'The unpacked top-level folder could not be located.');
        }

        try {
            $index = $this->files->readJson($server, '/'.trim($extractedFolder, '/').'/modrinth.index.json');
        } catch (Throwable $e) {
            throw $this->fail('unpack_failed', 'Reading modrinth.index.json failed.', $e);
        }

        if (!is_array($index) || !is_array($index['files'] ?? null)) {
            throw $this->fail('unpack_failed', 'modrinth.index.json holds no file table.');
        }

        $written = 0;
        $failed = [];
        $children = []; // '<relative path>' => 'plugin'|'mod'|null

        foreach ($index['files'] as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $relativePath = str_replace(['\\', '..'], '/', (string) ($entry['path'] ?? ''));
            $uris = is_array($entry['downloads'] ?? null) ? $entry['downloads'] : [];

            if (trim($relativePath, '/') === '' || $uris === []) {
                $failed[] = ['file' => $relativePath !== '' ? $relativePath : '(unnamed)', 'reason' => 'manifest_missing_path_or_uri'];

                continue;
            }

            $safePath = '/'.ltrim(preg_replace('/\/+/', '/', $relativePath) ?? $relativePath, '/');
            $parent = rtrim(dirname($safePath), '/\\');
            $basename = basename($safePath);

            $ok = false;

            foreach ($uris as $uriIndex => $uri) {
                if (!is_string($uri) || $uri === '') {
                    continue;
                }

                try {
                    $this->files->pull($server, $uri, $parent === '/' ? '/' : $parent);

                    $ok = true;

                    break;
                } catch (Throwable $e) {
                    $failed[] = [
                        'file' => ltrim($safePath, '/'),
                        'uri_index' => $uriIndex,
                        'reason' => mb_substr($e->getMessage(), 0, 120),
                    ];
                }
            }

            if ($ok) {
                ++$written;
                $children[ltrim($safePath, '/')] = $this->childKind(ltrim($safePath, '/'));
            } elseif (!isset($children[ltrim($safePath, '/')])) {
                $children[ltrim($safePath, '/')] = $this->childKind(ltrim($safePath, '/')) ; // null for non-code paths
            }
        }

        $trackedCount = count(array_filter($children, fn ($kind) => is_string($kind) && $kind !== ''));

        $summary = [
            'mode' => 'mrpack',
            'extracted_folder' => $extractedFolder,
            'files_written' => $written,
            'files_total' => count($index['files']),
            'files_tracked' => $trackedCount,
            'files_failed' => count(array_filter($failed)),
            'failure_log' => array_slice($failed, 0, 25),
            'children' => $children,
        ];

        return [$bundle, $trackedCount, $summary];
    }

    /** plugin↔plugins/, mod↔mods/ prefixes; everything else stays untracked (null). */
    private function childKind(string $relativePath): ?string
    {
        $lower = mb_strtolower(ltrim($relativePath, '/'));

        if (str_starts_with($lower, 'plugins/') || str_starts_with($lower, 'plugin/')) {
            return 'plugin';
        }

        if (str_starts_with($lower, 'mods/') || str_starts_with($lower, 'mod/')) {
            return 'mod';
        }

        return null;
    }

    /**
     * Persist per-file child rows produced by the mrpack flow (umbrella written separately).
     *
     * @param  array<string, string|null>  $children
     */
    private function trackChildRows(Server $server, array $children, string $source, string $projectId, string $title): void
    {
        $rows = [];

        foreach ($children as $path => $childKind) {
            if (!is_string($childKind) || $childKind === '') {
                continue;
            }

            $rows[] = ['path' => $path, 'kind' => $childKind];
        }

        if ($rows === []) {
            return;
        }

        try {
            DB::transaction(function () use ($server, $rows, $source, $projectId, $title) {
                foreach ($rows as $spec) {
                    $existing = InstalledContent::query()
                        ->where('server_id', $server->getKey())
                        ->where('file_name', $spec['path'])
                        ->first();

                    if ($existing instanceof InstalledContent) {
                        $existing->fill([
                            'kind' => $spec['kind'],
                            'registry' => $source,
                            'project_id' => $projectId,
                            'tracked_by_toolkit' => true,
                        ]);
                        $existing->save();

                        continue;
                    }

                    $row = new InstalledContent;

                    $row->server_id = $server->getKey();
                    $row->kind = $spec['kind'];
                    $row->registry = $source;
                    $row->project_id = $projectId;
                    $row->file_name = $spec['path'];
                    $row->dependencies = ['pack' => $title];
                    $row->tracked_by_toolkit = true;

                    $row->save();
                }
            });
        } catch (Throwable) {
            // Child-row persistence problems never invalidate the delivered files;
            // forgetMissing() reconciles them next visit.
        }
    }

    // -------------------------------------------------------------------------
    // Bookkeeping
    // -------------------------------------------------------------------------

    private function trackUmbrellaRow(
        Server $server,
        string $kind,
        string $source,
        string $projectId,
        string $title,
        string $fileName,
        array $summary,
        array $artifact,
    ): InstalledContent {
        $payloadSummary = $summary;

        unset($payloadSummary['children']);

        return DB::transaction(function () use ($server, $kind, $source, $projectId, $title, $fileName, $payloadSummary, $artifact) {
            $attributes = [
                'server_id' => $server->getKey(),
                'kind' => $kind,
                'registry' => $source,
                'project_id' => $projectId,
                'file_name' => $fileName,
                'version' => is_string($artifact['version'] ?? null) ? $artifact['version'] : null,
                'checksum' => is_string($artifact['checksum'] ?? null) ? $artifact['checksum'] : null,
                'dependencies' => ['title' => $title, 'summary' => $payloadSummary],
                'tracked_by_toolkit' => true,
            ];

            $existing = InstalledContent::query()
                ->where('server_id', $server->getKey())
                ->where('file_name', $fileName)
                ->first();

            if ($existing instanceof InstalledContent) {
                foreach ($attributes as $column => $value) {
                    if (in_array($column, ['server_id', 'file_name'], true)) {
                        continue;
                    }

                    $existing->{$column} = $value;
                }

                $existing->save();

                return $existing;
            }

            $row = new InstalledContent;

            foreach ($attributes as $column => $value) {
                $row->{$column} = $value;
            }

            $row->save();

            return $row;
        });
    }

    /** Wrapped so observability flakiness never rolls back an already-successful install. */
    private function logInstall(Server $server, string $title, string $file, int $childCount): void
    {
        try {
            Activity::event('mc-toolkit:content.install')
                ->property('project', $title)
                ->property('file', $file)
                ->when($childCount > 0, fn ($log) => $log->property('files_installed', $childCount))
                ->subject($server)
                ->log();
        } catch (Throwable) {
            // swallow intentionally
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Content directories wired from detection, falling back to vanilla defaults.
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

        return [
            'plugins' => is_string($dirs['plugins'] ?? null) && $dirs['plugins'] !== '' ? $dirs['plugins'] : 'plugins',
            'mods' => is_string($dirs['mods'] ?? null) && $dirs['mods'] !== '' ? $dirs['mods'] : 'mods',
        ];
    }

    private function ensureDirectory(Server $server, string $dir): void
    {
        try {
            if (!$this->files->exists($server, $dir)) {
                $this->files->mkdir($server, $dir);
            }
        } catch (Throwable) {
            // Duplicate-directory answers surface as 400s; genuine permission failures
            // show up during the pull itself.
        }
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

    /** Lowercase ROOT directory names (folder-diff discovery step of mrpack flows). */
    private function rootNames(Server $server): array
    {
        try {
            $entries = $this->files->ls($server, '.');
        } catch (Throwable) {
            return [];
        }

        $names = [];

        foreach ($entries as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null)) {
                $names[mb_strtolower($entry['name'])] = true;
            }
        }

        return $names;
    }

    private function freshFolder(array $before, array $after): ?string
    {
        $candidates = array_values(array_diff(array_keys($after), array_keys($before)));

        if ($candidates === []) {
            return null;
        }

        sort($candidates, SORT_STRING);

        return $candidates[0];
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
                && (bool) preg_match('/\.jar$/i', $entry['name']);
        }));
    }

    /**
     * Modrinth versions honoring hints, widening when nothing matches.
     *
     * @param  array<string, mixed>  $versionMeta
     * @return list<mixed>
     */
    private function requestModrinthVersions(string $projectId, array $versionMeta): array
    {
        $loader = strtolower(trim((string) ($versionMeta['loader'] ?? '')));
        $mcVersion = trim((string) ($versionMeta['mc_version'] ?? ''));

        $attempts = [];

        if ($loader !== '' && $mcVersion !== '') {
            $attempts[] = [
                'loaders' => json_encode([$loader]),
                'game_versions' => json_encode([$mcVersion]),
            ];
        }

        if ($mcVersion !== '') {
            $attempts[] = ['game_versions' => json_encode([$mcVersion])];
        }

        if ($loader !== '') {
            $attempts[] = ['loaders' => json_encode([$loader])];
        }

        $attempts[] = []; // widest last

        foreach ($attempts as $attempt) {
            $results = $this->modrinth->versions($projectId, $attempt);

            if ($results !== []) {
                return $results;
            }
        }

        return [];
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

    private static function platformMatches(string $actual, string $wanted): bool
    {
        if ($actual === $wanted) {
            return true;
        }

        return ($actual === 'FOLIA' && $wanted === 'PAPER')
            || ($actual === 'WATERFALL' && $wanted === 'BUNGEECORD')
            || ($actual === 'BUNGEECORD' && $wanted === 'VELOCITY');
    }

    /**
     * Extract scheme://host/ from any usable URL ("" when parsing fails).
     */
    private static function originOf(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return '';
        }

        return $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }

    private static function absolutizeRedirect(string $location, string $origin): string
    {
        $trimmed = trim($location);

        if ($trimmed === '') {
            return $origin;
        }

        if (str_starts_with(strtolower($trimmed), 'http')) {
            return $trimmed;
        }

        if (str_starts_with($trimmed, '//')) {
            $scheme = str_contains($origin, 'https:') ? 'https' : 'http';

            return $scheme.':'.$trimmed;
        }

        return $origin.$trimmed;
    }

    /** Filesystem-safe slug; keeps extensions intact when present, appends .jar otherwise. */
    private function sanitizeFileName(string $candidate, string $fallbackTitle): string
    {
        $clean = trim($candidate);

        if ($clean === '') {
            $slug = Str::slug($fallbackTitle);

            $clean = $slug !== '' ? $slug.'.jar' : 'toolkit-artifact.jar';
        }

        $clean = preg_replace('/[^A-Za-z0-9._\-]+/', '-', $clean) ?? $clean;
        $clean = trim($clean, '-.');

        return $clean !== '' && $clean !== '.' ? $clean : 'toolkit-artifact.jar';
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
