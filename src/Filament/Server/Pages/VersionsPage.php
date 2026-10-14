<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Http\Client\RequestException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use McToolkit\Services\Catalog\McJarsCatalogService;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Support\Activity;

/**
 * Versions — "Install or change the server software (Paper, Purpur, Fabric, Forge...)".
 *
 * Demo parity notes (see .research/demo-findings.md §Versions):
 *  - category buckets: Recommended / Established / Experimental / Miscellaneous / Limbo
 *  - type chips with "Installed" marker for the detected profile
 *  - filter tabs Releases / Snapshots / All + debounced search
 *  - rows: version id, Java badge, builds count, Installed badge, Install CTA
 *  - "Show more versions" accumulates pages instead of replacing the list
 *
 * Install flow — nothing on the server changes until every file is downloaded:
 *   1. validate the MCJars `installation` steps (download / unzip / remove, safe paths)
 *   2. pull every download in the FOREGROUND under its exact target name into a staging
 *      folder while the server keeps running (MCJars' `server.jar` is written under the
 *      egg's SERVER_JARFILE so the startup command keeps working)
 *   3. stop the server and wait until wings really reports it offline
 *   4. optional archive of the old jars + world folders (server offline = consistent)
 *   5. remove the previous software's runnable jars, then replay the steps in order:
 *      staged files are moved into place, archives unzipped into their `location`,
 *      `remove` steps applied
 *   6. record what was installed (detection marker), refresh detection, optional start
 * A failure before step 5 leaves the server untouched and restarts it when it was
 * running; a failure during step 5 leaves it stopped and says so.
 */
final class VersionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::Stack2;

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected string $view = 'minecraft-toolkit::server.versions';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/versions (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/versions';

    private const PER_PAGE = 50;

    /** Server-root folder downloads land in before they replace anything. */
    private const STAGING_DIR = '.mctoolkit-staging';

    /** How long to wait for the server to go offline after the stop signal. */
    private const STOP_WAIT_SECONDS = 60;

    /** Upper bound of one install run (downloads + archive + unzip). */
    private const INSTALL_LOCK_SECONDS = 1800;

    /** Sanity cap on download steps per build. */
    private const MAX_DOWNLOADS = 20;

    /** Active category bucket key (recommended|established|experimental|miscellaneous|limbo). */
    public string $bucket = 'recommended';

    /** Selected software type id, e.g. 'PAPER' (validated against the catalog on change). */
    public string $type = '';

    /** Row filter tab: releases|snapshots|all (changed through setTab() only). */
    #[Locked]
    public string $tab = 'releases';

    /** Debounced search phrase. */
    public string $search = '';

    /** Last loaded page of the version list. */
    #[Locked]
    public int $page = 1;

    /** @var list<array{id:string,type:string,java:?int,builds:?int,created:?string}> accumulated rows across "Show more" pages */
    #[Locked]
    public array $versions = [];

    #[Locked]
    public int $total = 0;

    /** Whether the catalog has further pages for the current type/search. */
    #[Locked]
    public bool $hasMore = false;

    #[Locked]
    public bool $busy = false;

    /** Pending install dialog target (null = closed); only set server-side by askInstall(). */
    #[Locked]
    public ?array $pending = null;

    public bool $backupFirst = false;

    public bool $startAfter = true;

    /** Detection profile shown in the banner and used for the "Installed" markers. */
    #[Locked]
    public ?array $profile = null;

    /**
     * Per-request memo of McJarsCatalogService::types() (HTTP-cached itself). Private
     * properties do not survive Livewire round trips, so every reader goes through
     * catalogTypes(), which reloads it when needed.
     *
     * @var array<string, array<string, array<string, mixed>>>|null
     */
    private ?array $typeCache = null;

    // ------------------------------------------------------------------ boot

    public function mount(): void
    {
        $this->refreshProfile();
        $this->loadTypes(preferInstalled: true);
    }

    /**
     * Checked on mount AND on every later Livewire request (CanAuthorizeAccess hydrate
     * hook), so a page opened before a suspension or a permission change stops working.
     */
    public static function canAccess(): bool
    {
        if (! parent::canAccess()) {
            return false;
        }

        // Admin "Enabled pages" toggle (same guard every other toolkit page applies).
        if (! (bool) config('minecraft-toolkit.pages.enabled.versions', true)) {
            return false;
        }

        $server = Filament::getTenant();

        if (! $server instanceof Server || $server->isInConflictState()) {
            return false;
        }

        $user = user();

        return $user !== null
            && $user->can('minecraft-toolkit.use', $server)
            && $user->can('minecraft-toolkit.versions', $server);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.versions.title');
    }

    public function getSubheading(): ?string
    {
        return trans('minecraft-toolkit::pages.versions.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.versions.title');
    }

    public static function getNavigationGroup(): ?string
    {
        return trans('minecraft-toolkit::common.nav.group_label');
    }

    // ------------------------------------------------------------- header ui

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('refreshData')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(\App\Enums\TablerIcon::Refresh)
                ->color('gray')
                ->action(fn () => $this->refreshData()),
            Action::make('detectAgain')
                ->label(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->icon(\App\Enums\TablerIcon::RefreshDot)
                ->color('gray')
                ->action(fn () => $this->redetect()),
        ];
    }

    private function refreshData(): void
    {
        // catalog payloads are HTTP-cache-backed; page state reloads transparently
        $this->refreshProfile();
        $this->loadTypes();
    }

    /**
     * Banner emits `$wire.dispatch('mc-toolkit.redetect')`.
     */
    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        $server = $this->server();

        try {
            $profile = app(DetectionService::class)->detect($server, force: true);
        } catch (\Throwable) {
            $profile = null;
        }

        $this->profile = $profile;

        if (! is_array($profile) || ($profile['reachable'] ?? true) === false) {
            $this->notifyGatewayDown();
        } else {
            Activity::event('mc-toolkit:detection.refresh')
                ->subject($server)
                ->log();

            $software = (string) ($profile['software'] ?? 'unknown');
            $label = ucfirst($software);
            $isUnknown = strcasecmp($software, 'unknown') === 0;

            $notification = Notification::make()
                ->title($isUnknown
                    ? trans('minecraft-toolkit::banner.failures.not_minecraft')
                    : trans('minecraft-toolkit::banner.detection.title_note'))
                ->body(filled($profile['version'] ?? null) ? $label.' — '.$profile['version'] : $label);

            ($isUnknown ? $notification->warning() : $notification->success())->send();
        }

        $this->loadTypes();
    }

    // ------------------------------------------------------------ live state

    public function updatedBucket(): void
    {
        $this->loadTypes(); // re-validates the bucket and refreshes the version list
    }

    public function updatedType(): void
    {
        $type = McJarsCatalogService::normalizeType($this->type);
        $inBucket = $this->catalogTypes()[$this->bucket] ?? [];

        $this->type = $type !== null && isset($inBucket[$type])
            ? $type
            : ($this->installedTypeInBucket() ?? (string) (array_key_first($inBucket) ?? ''));

        $this->reloadVersions(reset: true);
    }

    public function updatedSearch(): void
    {
        $this->search = mb_substr(trim($this->search), 0, 64);

        $this->reloadVersions(reset: true);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['releases', 'snapshots', 'all'], true) ? $tab : 'all';
        $this->reloadVersions(reset: true);
    }

    public function showMore(): void
    {
        if (! $this->hasMore) {
            return;
        }

        $this->page++;
        $this->reloadVersions(reset: false);
    }

    // --------------------------------------------------------- install ask/sheet state

    /**
     * Opens the confirmation sheet for one of the rows currently listed for the
     * selected type — anything else is ignored.
     */
    public function askInstall(string $version): void
    {
        if (! $this->canManage($this->server())) {
            $this->notifyNotAllowed();

            return;
        }

        $row = null;

        foreach ($this->versions as $candidate) {
            if (($candidate['id'] ?? null) === $version) {
                $row = $candidate;

                break;
            }
        }

        if ($row === null || ! $this->isCatalogType($this->type)) {
            return;
        }

        $this->backupFirst = false;
        $this->startAfter = true;
        $this->pending = [
            'type' => $this->type,
            'version' => $version,
            'java' => is_int($row['java'] ?? null) ? $row['java'] : null,
        ];
    }

    public function closeAsk(): void
    {
        $this->pending = null;
    }

    public function canBackup(): bool
    {
        $server = $this->server();

        return (int) $server->getAttribute('backup_limit') > 0
            && (bool) user()?->can(SubuserPermission::BackupCreate, $server);
    }

    // ------------------------------------------------------------------ runs

    public function runInstall(): void
    {
        $server = $this->server();

        $pending = $this->pending;
        $this->pending = null;

        if (! $this->canManage($server)) {
            $this->notifyNotAllowed();

            return;
        }

        $type = McJarsCatalogService::normalizeType((string) ($pending['type'] ?? ''));
        $version = trim((string) ($pending['version'] ?? ''));

        if ($type === null || $version === '' || ! $this->isCatalogType($type)) {
            return;
        }

        // One install per server at a time (double clicks, second browser tab). A cache
        // store without lock support simply skips the guard.
        try {
            $lock = cache()->lock('mctoolkit.install.'.$server->uuid, self::INSTALL_LOCK_SECONDS);
            $acquired = $lock->get();
        } catch (\Throwable) {
            $lock = null;
            $acquired = true;
        }

        if (! $acquired) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.versions.install.already_running'))
                ->warning()
                ->send();

            return;
        }

        $this->busy = true;

        $bridge = app(FileBridge::class);
        $wasRunning = false;
        $touched = false;

        try {
            $build = app(McJarsCatalogService::class)->latestBuild($type, $version);
            $plan = $build === null ? null : $this->planInstallation($build['build']['installation'], $server);

            if ($plan === null) {
                $this->notifyFailure('download_failed');

                return;
            }

            // 1) every download lands in staging first; the server is not touched yet
            $this->deleteQuietly($server, self::STAGING_DIR);

            foreach ($plan['downloads'] as $download) {
                $bridge->pull($server, $download['url'], '/'.$download['stage_dir'], $download['name']);
            }

            // 2) the container must be offline before binaries change
            $wasRunning = $this->stopAndAwaitOffline($server);

            // 3) optional archive of what is about to be replaced
            if ($this->backupFirst && $this->canBackup()) {
                $this->archiveCurrentInstall($server, $bridge, $version);
            }

            // 4) swap: previous runnable jars out, staged files in, steps replayed
            $doomed = $this->previousSoftwareFiles($server, $bridge, $plan);

            $touched = true;

            $bridge->delete($server, $doomed, '/');
            $this->replaySteps($server, $bridge, $plan);
            $placed = $this->verifyPlaced($server, $bridge, $plan);
            $this->deleteQuietly($server, self::STAGING_DIR);

            // 5) remember what was installed (detection is re-run in `finally`)
            $this->writeInstallMarker($server, $bridge, $type, $version, $build['build']['name'], $placed);

            Activity::event('mc-toolkit:versions.install')
                ->subject($server)
                ->property([
                    'software' => ucfirst(strtolower($type)),
                    'version' => $version,
                    'build_name' => $build['build']['name'],
                ])
                ->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.installed'))
                ->body(ucfirst(strtolower($type)).' '.$version)
                ->success()
                ->send();

            // 6) optional autostart
            if ($this->startAfter) {
                try {
                    app(DaemonServerRepository::class)->setServer($server)->power('start');
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(trans('minecraft-toolkit::errors.statuses.wings_502'))
                        ->danger()
                        ->send();
                }
            }
        } catch (StopTimedOut) {
            // Stop never confirmed offline: abort WITHOUT restarting — the container is
            // mid-stop and nothing has been replaced.
            $this->cleanupStaging($server);

            Notification::make()
                ->title(trans('minecraft-toolkit::pages.versions.install.stop_timeout'))
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            $this->cleanupStaging($server);

            // Nothing was replaced yet: put the server back the way it was.
            if (! $touched && $wasRunning) {
                try {
                    app(DaemonServerRepository::class)->setServer($server)->power('start');
                } catch (\Throwable) {
                    // swallow — node unreachable mid-recovery
                }
            }

            Notification::make()
                ->title(trans(match (true) {
                    $e instanceof BackupFailed => 'minecraft-toolkit::pages.versions.install.backup_failed',
                    $e instanceof UnpackFailed => 'minecraft-toolkit::errors.statuses.unpack_failed',
                    $touched => 'minecraft-toolkit::pages.versions.install.swap_failed',
                    default => 'minecraft-toolkit::errors.statuses.download_failed',
                }))
                ->body($touched ? trans('minecraft-toolkit::pages.versions.install.left_stopped') : null)
                ->danger()
                ->send();
        } finally {
            $lock?->release();

            $this->busy = false;
            // After a swap (successful or not) the cached detection describes old files.
            $this->refreshProfile(force: $touched);
            $this->reloadVersions(reset: true);
        }
    }

    // ------------------------------------------------------------- internals

    /**
     * Validate MCJars' installation groups and turn them into a download list plus an
     * ordered replay script. Any unknown step type, unsafe path or non-http(s) URL
     * rejects the whole build — a half-understood install would break the server.
     *
     * @param  list<list<array<string, mixed>>>  $installation
     * @return array{
     *     downloads: list<array{url: string, stage_dir: string, name: string, target: string}>,
     *     steps: list<array{type: string, from?: string, to?: string, file?: string, location?: string}>,
     *     targets: list<string>,
     *     expected: list<string>,
     * }|null
     */
    private function planInstallation(array $installation, Server $server): ?array
    {
        $jarfile = $this->serverJarfile($server);
        $downloads = [];
        $steps = [];
        $rename = [];
        $removed = [];

        foreach ($installation as $group) {
            foreach ($group as $step) {
                $kind = (string) ($step['type'] ?? '');

                if ($kind === 'download') {
                    $url = $step['url'] ?? null;
                    $file = self::relativePath((string) ($step['file'] ?? ''));

                    if (! is_string($url) || preg_match('#^https?://#i', $url) !== 1 || $file === null || $file === '') {
                        return null;
                    }

                    $target = $file;

                    // MCJars names the runnable server.jar; the egg starts {{SERVER_JARFILE}}.
                    if ($file === 'server.jar' && $jarfile !== null && $jarfile !== 'server.jar') {
                        $rename[$file] = $jarfile;
                        $target = $jarfile;
                    }

                    $stageDir = self::STAGING_DIR.'/'.count($downloads);
                    $name = basename($target);

                    $downloads[] = ['url' => $url, 'stage_dir' => $stageDir, 'name' => $name, 'target' => $target];
                    $steps[] = ['type' => 'place', 'from' => $stageDir.'/'.$name, 'to' => $target];

                    continue;
                }

                if ($kind === 'unzip') {
                    $file = self::relativePath((string) ($step['file'] ?? ''));
                    $location = self::relativePath((string) ($step['location'] ?? '.'));

                    if ($file === null || $file === '' || $location === null) {
                        return null;
                    }

                    $steps[] = ['type' => 'unzip', 'file' => $rename[$file] ?? $file, 'location' => $location];

                    continue;
                }

                if ($kind === 'remove') {
                    $location = self::relativePath((string) ($step['location'] ?? ''));

                    if ($location === null || $location === '') {
                        return null;
                    }

                    $location = $rename[$location] ?? $location;
                    $removed[] = $location;
                    $steps[] = ['type' => 'remove', 'location' => $location];

                    continue;
                }

                return null;
            }
        }

        if ($downloads === [] || count($downloads) > self::MAX_DOWNLOADS) {
            return null;
        }

        $targets = array_column($downloads, 'target');

        return [
            'downloads' => $downloads,
            'steps' => $steps,
            'targets' => $targets,
            // Downloaded files that should still exist once every step ran.
            'expected' => array_values(array_diff($targets, $removed)),
        ];
    }

    /**
     * Normalize a server-relative path from upstream: forward slashes, no leading slash,
     * no `.` segments; '' means the server root; null for traversal or NUL bytes.
     */
    private static function relativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function directoryOf(string $relative): string
    {
        $slash = strrpos($relative, '/');

        return $slash === false ? '' : substr($relative, 0, $slash);
    }

    /**
     * The egg's SERVER_JARFILE when it is a plain root-level jar name, else null.
     */
    private function serverJarfile(Server $server): ?string
    {
        try {
            $variable = $server->variables()->where('env_variable', 'SERVER_JARFILE')->first();
        } catch (\Throwable) {
            return null;
        }

        $value = self::relativePath((string) ($variable?->server_value ?? $variable?->default_value ?? ''));

        return $value !== null && $value !== '' && ! str_contains($value, '/') && str_ends_with(mb_strtolower($value), '.jar')
            ? $value
            : null;
    }

    /**
     * Pre-install archive: root runnable jars plus top-level world folders.
     */
    private function archiveCurrentInstall(Server $server, FileBridge $bridge, string $version): void
    {
        try {
            $names = $this->installScope($server, $bridge)['names'];

            if ($names !== []) {
                $label = preg_replace('/[^A-Za-z0-9._-]+/', '_', $version) ?? 'version';

                $bridge->compress($server, '/', $names, 'mct-pre-'.$label.'-'.now()->format('YmdHis'), 'zip');
            }
        } catch (\Throwable $e) {
            throw new BackupFailed('The pre-install archive could not be created.', 0, $e);
        }
    }

    /**
     * Root files to remove before the swap: the previous software's runnable jars
     * (anything detection recognises as server software, the egg's SERVER_JARFILE, and
     * every file the new build is about to place there) plus the stale install marker.
     * Unrelated jars stay. Read-only — the caller deletes.
     *
     * @param  array{targets: list<string>}  $plan
     * @return list<string>
     */
    private function previousSoftwareFiles(Server $server, FileBridge $bridge, array $plan): array
    {
        $detection = app(DetectionService::class);
        $targets = array_map('mb_strtolower', $plan['targets']);
        $jarfile = $this->serverJarfile($server);
        $jarfile = $jarfile === null ? null : mb_strtolower($jarfile);

        $doomed = [];

        foreach ($bridge->ls($server, '/') as $row) {
            if ($row['directory'] || ! ($row['file'] || $row['symlink'])) {
                continue;
            }

            $name = $row['name'];
            $lower = mb_strtolower($name);

            if ($lower === mb_strtolower(DetectionService::INSTALL_MARKER)) {
                $doomed[] = $name;

                continue;
            }

            if (str_ends_with($lower, '.jar')
                && ($detection->isServerSoftwareJar($name) || $lower === $jarfile || in_array($lower, $targets, true))) {
                $doomed[] = $name;
            }
        }

        return $doomed;
    }

    /**
     * Replay the validated steps: staged files move into place (wings' rename refuses an
     * existing target, so it is cleared first), archives are extracted into their
     * `location` (wings extracts next to the archive, so it is moved there first), and
     * `remove` steps delete their path — following a relocated archive.
     *
     * @param  array{steps: list<array{type: string, from?: string, to?: string, file?: string, location?: string}>}  $plan
     */
    private function replaySteps(Server $server, FileBridge $bridge, array $plan): void
    {
        $relocated = [];

        foreach ($plan['steps'] as $step) {
            switch ($step['type']) {
                case 'place':
                    $this->deleteQuietly($server, (string) $step['to']);
                    $bridge->rename($server, '/'.$step['from'], '/'.$step['to']);

                    break;

                case 'unzip':
                    $file = (string) $step['file'];
                    $location = (string) $step['location'];

                    if (self::directoryOf($file) !== $location) {
                        $moved = ltrim($location.'/'.basename($file), '/');

                        $this->deleteQuietly($server, $moved);
                        $bridge->rename($server, '/'.$file, '/'.$moved);

                        $relocated[$file] = $moved;
                        $file = $moved;
                    }

                    try {
                        $bridge->decompress($server, '/'.$location, basename($file));
                    } catch (\Throwable $e) {
                        throw new UnpackFailed('The archive could not be unpacked.', 0, $e);
                    }

                    break;

                case 'remove':
                    $location = (string) $step['location'];

                    $this->deleteQuietly($server, $relocated[$location] ?? $location);

                    break;
            }
        }
    }

    /**
     * Wings' rename answers 204 even when the source vanished, so check that every
     * root-level file the build should leave behind is really there.
     *
     * @param  array{expected: list<string>}  $plan
     * @return array<string, int> root-level placed file => size
     */
    private function verifyPlaced(Server $server, FileBridge $bridge, array $plan): array
    {
        $sizes = [];

        foreach ($bridge->ls($server, '/') as $row) {
            if (! $row['directory']) {
                $sizes[$row['name']] = $row['size'];
            }
        }

        $placed = [];

        foreach ($plan['expected'] as $target) {
            if (str_contains($target, '/')) {
                continue;
            }

            if (! array_key_exists($target, $sizes)) {
                throw new \RuntimeException("Installed file [{$target}] is missing after the swap.");
            }

            $placed[$target] = $sizes[$target];
        }

        return $placed;
    }

    /**
     * Lets DetectionService recognise this install even though MCJars' runnable is a
     * generic `server.jar`. Skipped (and any older marker already swept) when no
     * root-level file of the build survives, e.g. archive-only installs.
     *
     * @param  array<string, int>  $placed
     */
    private function writeInstallMarker(Server $server, FileBridge $bridge, string $type, string $version, string $buildName, array $placed): void
    {
        if ($placed === []) {
            return;
        }

        try {
            $bridge->write($server, DetectionService::INSTALL_MARKER, json_encode([
                'software' => $type,
                'version' => $version,
                'build' => $buildName,
                'files' => $placed,
                'installed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (\Throwable $e) {
            // Detection then falls back to file heuristics; the install itself succeeded.
            report($e);
        }
    }

    /**
     * Delete a server-relative path, treating "already gone" as success.
     */
    private function deleteQuietly(Server $server, string $relative): void
    {
        $relative = trim($relative, '/');

        if ($relative === '') {
            return;
        }

        $bridge = app(FileBridge::class);

        if (! $bridge->exists($server, '/'.$relative)) {
            return;
        }

        try {
            $bridge->delete($server, [$relative], '/');
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }

    private function cleanupStaging(Server $server): void
    {
        try {
            $this->deleteQuietly($server, self::STAGING_DIR);
        } catch (\Throwable) {
            // best-effort: a leftover staging folder is harmless and swept next time
        }
    }

    /**
     * Archive scope: root *.jar files plus top-level world directories,
     * exactly like the contract note (`limited scope`).
     *
     * @return array{names:list<string>}
     */
    private function installScope(Server $server, FileBridge $bridge): array
    {
        $rows = $bridge->ls($server, '/');
        $names = [];

        foreach ($rows as $row) {
            $isJar = $row['file'] && str_ends_with(mb_strtolower($row['name']), '.jar');
            $isWorldDir = $row['directory'] && str_starts_with(mb_strtolower($row['name']), 'world');

            if ($isJar || $isWorldDir) {
                $names[] = $row['name'];
            }
        }

        return ['names' => $names];
    }

    /**
     * Stops the container when it runs and waits until wings reports it offline,
     * clearing Server::retrieveStatus()'s 15-second cache before every read.
     *
     * @return bool whether the server was running (and should be restarted on failure)
     *
     * @throws StopTimedOut when it is still not offline after STOP_WAIT_SECONDS
     */
    private function stopAndAwaitOffline(Server $server): bool
    {
        $status = $this->freshStatus($server);

        if (self::isStopped($status)) {
            return false;
        }

        if ($status === ContainerStatus::Missing) {
            throw new \RuntimeException('The server state could not be read from the node.');
        }

        $wasRunning = $status !== ContainerStatus::Stopping;

        if ($status !== ContainerStatus::Stopping) {
            app(DaemonServerRepository::class)->setServer($server)->power('stop');
        }

        for ($i = 0; $i < self::STOP_WAIT_SECONDS; $i++) {
            sleep(1);

            try {
                if (self::isStopped($this->freshStatus($server))) {
                    return $wasRunning;
                }
            } catch (\Throwable) {
                // connection hiccup — keep polling until budget spent
            }
        }

        throw new StopTimedOut;
    }

    private function freshStatus(Server $server): ContainerStatus
    {
        cache()->forget('servers.'.$server->uuid.'.status');

        return $server->retrieveStatus();
    }

    private static function isStopped(ContainerStatus $status): bool
    {
        return in_array($status, [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Dead], true);
    }

    private function canManage(Server $server): bool
    {
        $user = user();

        return $user !== null
            && ! $server->isInConflictState()
            && $user->can('minecraft-toolkit.versions', $server)
            && $user->can('minecraft-toolkit.manage', $server);
    }

    private function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    // --------------------------------------------------------------- loaders

    private function refreshProfile(bool $force = false): void
    {
        try {
            $this->profile = app(DetectionService::class)->detect($this->server(), $force);
        } catch (\Throwable) {
            $this->profile = null;
        }
    }

    /**
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function catalogTypes(): array
    {
        if ($this->typeCache === null) {
            try {
                $this->typeCache = app(McJarsCatalogService::class)->types();
            } catch (\Throwable) {
                $this->typeCache = [];
            }
        }

        return $this->typeCache;
    }

    private function isCatalogType(string $type): bool
    {
        $type = McJarsCatalogService::normalizeType($type);

        if ($type === null) {
            return false;
        }

        foreach ($this->catalogTypes() as $types) {
            if (isset($types[$type])) {
                return true;
            }
        }

        return false;
    }

    private function loadTypes(bool $preferInstalled = false): void
    {
        $types = $this->catalogTypes();

        // On first load jump to the bucket holding the installed software.
        if ($preferInstalled && ($installed = $this->installedTypeId()) !== null) {
            foreach ($types as $bucket => $bucketTypes) {
                if (isset($bucketTypes[$installed])) {
                    $this->bucket = (string) $bucket;
                    $this->type = $installed;

                    break;
                }
            }
        }

        if (! isset($types[$this->bucket])) {
            $this->bucket = (string) (array_key_first($types) ?? 'recommended');
        }

        $inBucket = $types[$this->bucket] ?? [];

        if ($this->type === '' || ! isset($inBucket[$this->type])) {
            // array_key_first() is null for an empty/unreachable catalog: '' = no type.
            $this->type = $this->installedTypeInBucket() ?? (string) (array_key_first($inBucket) ?? '');
        }

        $this->reloadVersions(reset: true);
    }

    /** Detected software as a catalog type id (PAPER, FABRIC…), null when unknown. */
    private function installedTypeId(): ?string
    {
        $software = (string) ($this->profile['software'] ?? '');

        if ($software === '' || strcasecmp($software, 'unknown') === 0) {
            return null;
        }

        return McJarsCatalogService::normalizeType($software);
    }

    private function installedTypeInBucket(): ?string
    {
        $installed = $this->installedTypeId();

        return $installed !== null && isset($this->catalogTypes()[$this->bucket][$installed])
            ? $installed
            : null;
    }

    private function reloadVersions(bool $reset): void
    {
        if ($reset) {
            $this->page = 1;
        }

        if ($this->type === '') {
            $this->versions = [];
            $this->total = 0;
            $this->hasMore = false;

            return;
        }

        try {
            $result = app(McJarsCatalogService::class)->versions(
                $this->type,
                page: $this->page,
                perPage: self::PER_PAGE,
                search: trim($this->search),
                snapshotsIn: $this->tab !== 'releases',
            );
        } catch (\Throwable) {
            $result = ['total' => 0, 'has_more' => false, 'data' => []];
        }

        $rows = $result['data'];

        if ($this->tab === 'snapshots') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r): bool => in_array(($r['type'] ?? ''), ['SNAPSHOT', 'BETA'], true),
            ));
        }

        // Upstream pages can shift while paging; keep the first occurrence of each id.
        $merged = $reset ? $rows : array_merge($this->versions, $rows);
        $this->versions = array_values(array_column($merged, null, 'id'));

        $this->total = (int) ($result['total'] ?? 0);
        // Pagination runs over the UNFILTERED upstream list, so "more" comes from upstream
        // too — comparing the filtered row count with the total never settles.
        $this->hasMore = (bool) ($result['has_more'] ?? false);
    }

    // -------------------------------------------------------------- view vm

    /**
     * Types of the active bucket, ascending by upstream order.
     *
     * @return list<array{key:string,label:string,desc:?string,installed:bool}>
     */
    public function getTypeChipsProperty(): array
    {
        $out = [];

        foreach ($this->catalogTypes()[$this->bucket] ?? [] as $id => $meta) {
            $label = is_string($meta['name'] ?? null) ? $meta['name'] : (string) $id;
            $desc = is_string($meta['description'] ?? null) && filled($meta['description']) ? $meta['description'] : null;

            $out[] = [
                'key' => (string) $id,
                'label' => $label,
                'desc' => $desc,
                'installed' => $this->isInstalledType((string) $id),
            ];
        }

        return $out;
    }

    private function isInstalledType(string $id): bool
    {
        $installed = $this->installedTypeId();

        return $installed !== null && mb_strtoupper($id) === $installed;
    }

    public function getVersionPropsProperty(): array
    {
        $profileVersion = (string) ($this->profile['version'] ?? '');
        $sameSoftware = $this->type !== '' && $this->isInstalledType($this->type);

        return array_map(static function (array $r) use ($profileVersion, $sameSoftware): array {
            $r += ['java' => null, 'builds' => null, 'type' => ''];

            return $r + [
                'installed' => $sameSoftware && $profileVersion !== '' && $r['id'] === $profileVersion,
            ];
        }, $this->versions);
    }

    private function notifyFailure(string $statusKey): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::errors.statuses.'.$statusKey))
            ->danger()
            ->send();
    }

    private function notifyNotAllowed(): void
    {
        $this->notifyFailure($this->server()->isInConflictState() ? 'server_unavailable' : 'permission_denied');
    }

    private function notifyGatewayDown(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
            ->danger()
            ->send();
    }
}

/**
 * Internal signal: the stop→offline wait burned its full budget.
 * Handled separately so we neither continue file IO nor auto-restart.
 */
final class StopTimedOut extends \RuntimeException {}

/** Internal signal: the requested pre-install archive failed (nothing replaced yet). */
final class BackupFailed extends \RuntimeException {}

/** Internal signal: wings could not extract an archive of the build (mid-swap). */
final class UnpackFailed extends \RuntimeException {}
