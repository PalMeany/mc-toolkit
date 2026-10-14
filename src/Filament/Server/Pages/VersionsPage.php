<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonFileRepository;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
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
 * Install flow (contract §INSTALL FLOW):
 *   backup archive → stop server → clear old jars → replay MCJars installation[]
 *   step groups via wings (pull/unzip/remove) → chmod best-effort → refresh
 *   detection → optional autostart. Every user-visible failure maps to a
 *   danger Notification with `minecraft-toolkit::errors.statuses.*` copy and
 *   power state is restored on the exception path.
 */
final class VersionsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::Stack2;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationGroup = 'Minecraft';

    protected string $view = 'minecraft-toolkit::server.versions';

    /** Active category bucket key (recommended|established|experimental|miscellaneous|limbo). */
    public string $bucket = 'recommended';

    /** Selected software type id, e.g. 'PAPER'. */
    public string $type = '';

    /** Row filter tab: releases|snapshots|all */
    public string $tab = 'releases';

    /** Debounced search phrase. */
    public string $search = '';

    /** Accumulated rows across "Show more" pages. */
    public int $page = 1;

    /** @var list<array{id:string,type:string,java:?int,builds:?int,created:?string}> */
    public array $versions = [];

    public int $total = 0;

    public bool $busy = false;

    /** Pending install dialog target (null = closed). */
    public ?array $pending = null;

    public bool $backupFirst = false;

    public bool $startAfter = true;

    /** @var array<string,array<string,mixed>>|null grouped catalog types per bucket */
    private ?array $typeCache = null;

    // ------------------------------------------------------------------ boot

    public function mount(): void
    {
        $this->authorizeAccess();
        $this->refreshProfile();
        $this->loadTypes();
    }

    public static function canAccess(): bool
    {
        // Admin "Enabled pages" toggle (same guard every other toolkit page applies).
        if (! (bool) config('minecraft-toolkit.pages.enabled.versions', true)) {
            return false;
        }

        return parent::canAccess()
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.versions', Filament::getTenant()), 403);
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
                ->action(fn () => $this->refreshAction()),
            Action::make('detectAgain')
                ->label(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->icon(\App\Enums\TablerIcon::RefreshDot)
                ->color('gray')
                ->action(fn () => $this->redetect()),
        ];
    }

    private function refreshAction(): void
    {
        // catalog payloads are HTTP-cache-backed; page state reloads transparently
        $this->refreshProfile();
        $this->loadTypes();
        $this->reloadVersions(reset: true);
    }

    /**
     * Banner emits `$wire.dispatch('mc-toolkit.redetect')`.
     */
    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $profile = app(DetectionService::class)->detect($server, force: true);

            Activity::event('mc-toolkit:detection.refresh')
                ->subject($server)
                ->log();

            $sw = ucfirst((string) ($profile['software'] ?? 'unknown'));

            Notification::make()
                ->title(
                    strcasecmp((string) ($profile['software'] ?? ''), 'unknown') === 0
                        ? trans('minecraft-toolkit::banner.failures.not_minecraft')
                        : trans('minecraft-toolkit::banner.detection.title_note')
                )
                ->body(filled($profile['version'] ?? null)
                    ? $sw.' — '.$profile['version']
                    : $sw)
                ->success()
                ->send();
        } catch (\Throwable) {
            $this->notifyGatewayDown();
        }

        $this->refreshProfile();
        $this->loadTypes();
        $this->reloadVersions(reset: true);
    }

    // ------------------------------------------------------------ live state

    public function updatedBucket(): void
    {
        $this->loadTypes(); // internally refreshes the version list for the new bucket
    }

    public function updatedType(): void
    {
        $this->reloadVersions(reset: true);
    }

    public function updatedSearch(): void
    {
        $this->reloadVersions(reset: true);
    }

    public function setTab(string $tab): void
    {
        $this->tab = in_array($tab, ['releases', 'snapshots', 'all'], true) ? $tab : 'all';
        $this->reloadVersions(reset: true);
    }

    public function showMore(): void
    {
        $this->page++;
        $this->reloadVersions(reset: false);
    }

    // --------------------------------------------------------- install ask/sheet state

    public function askInstall(string $type, string $version): void
    {
        $this->backupFirst = false;
        $this->startAfter = true;
        $this->pending = ['type' => strtoupper($type), 'version' => $version];
    }

    public function closeAsk(): void
    {
        $this->pending = null;
    }

    public function canBackup(): bool
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return (int) $server->getAttribute('backup_limit') > 0
            && (bool) user()?->can(SubuserPermission::BackupCreate, $server);
    }

    // ------------------------------------------------------------------ runs

    public function runInstall(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        abort_unless(
            user()?->can('minecraft-toolkit.manage', $server)
            && user()?->can('minecraft-toolkit.versions', $server),
            403,
        );

        [$type, $version] = [
            (string) ($this->pending['type'] ?? ''),
            (string) ($this->pending['version'] ?? ''),
        ];

        if ($type === '' || $version === '' || $this->busy) {
            return;
        }

        $this->busy = true;
        $this->pending = null;

        $bridge = app(FileBridge::class);
        $catalog = app(McJarsCatalogService::class);
        $wasRunning = false;

        try {
            $build = $catalog->latestBuild($type, $version);

            if ($build === null || ($build['build']['installation'] ?? []) === []) {
                $this->notifyFailure('download_failed');

                return;
            }

            // 1) optional pre-backup of current server binaries
            if ($this->backupFirst && $this->canBackup()) {
                try {
                    $scope = $this->installScope($server, $bridge);
                    $ts = now()->format('YmdHis');

                    if ($scope['names'] !== []) {
                        $bridge->compress($server, '/', $scope['names'], 'mct-pre-'.$version.'-'.$ts, 'zip');
                        // archive name implicit: cleanup targets mct-pre-*.zip only (see safeLeftovers note)
                    }
                } catch (\Throwable $e) {
                    report($e);

                    Notification::make()
                        ->title(trans('minecraft-toolkit::errors.statuses.wings_502'))
                        ->warning()
                        ->send();
                    // parity: proceed even when the snapshot failed
                }
            }

            // 2) make sure container is offline before touching binaries
            $wasRunning = $this->isRunning($server);

            if ($wasRunning) {
                $this->stopAndAwaitOffline($server);
            }

            // 3) remove old root jars (never recursive-delete the whole root)
            $rootJars = array_map(
                fn (array $row): string => $row['name'],
                $this->jarRows($server, $bridge),
            );

            if ($rootJars !== []) {
                $bridge->delete($server, $rootJars, '/');
            }

            /*
             * Old loader leftovers safe-list — single files only, never directories:
             * fabric-server-launch.jar is regenerated by the new build's steps; other
             * artifacts from previous installs (e.g. purpur/paperclip temp jars) are
             * covered by the root *.jar sweep above. Deeper trees stay untouched.
             */
            $safeLeftovers = ['fabric-server-launch.jar'];
            $leftovers = array_values(array_intersect(
                $bridge->names($server, '/'),
                $safeLeftovers,
            ));

            if ($leftovers !== []) {
                $bridge->delete($server, $leftovers, '/');
            }

            // 4) replay MCJars installation step groups in upstream order
            foreach ($build['build']['installation'] as $group) {
                foreach ($group as $step) {
                    match ($step['type'] ?? '') {
                        'download' => $this->pullStep($server, $bridge, $catalog, $step),
                        'unzip' => $bridge->decompress($server, '/', (string) ($step['file'] ?? '')),
                        'remove' => $this->removeStep($server, $bridge, (string) ($step['location'] ?? '')),
                        default => null,
                    };
                }
            }

            // 5) defensive chmod on new executables (best-effort, silent)
            $this->chmodSilently($server, array_merge($rootJars, $safeLeftovers));

            // 6) fresh detection so chips/badges reflect reality at once
            app(DetectionService::class)->detect($server, force: true);

            Activity::event('mc-toolkit:versions.install')
                ->subject($server)
                ->property([
                    'software' => ucfirst(strtolower($type)),
                    'version' => $version,
                    'build_name' => (string) ($build['build']['name'] ?? ''),
                ])
                ->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.installed'))
                ->body(ucfirst(strtolower($type)).' '.$version)
                ->success()
                ->send();

            // 7) optional autostart
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
            // stop never confirmed offline in 30 s — abort WITHOUT restarting,
            // container is mid-stop transition and user intent was still "install"
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.wings_502'))
                ->danger()
                ->send();
        } catch (\Throwable $e) {
            report($e);

            // best-effort restore of original power state
            if ($wasRunning) {
                try {
                    app(DaemonServerRepository::class)->setServer($server)->power('start');
                } catch (\Throwable) {
                    // swallow — node unreachable mid-recovery
                }
            }

            $this->notifyFailure('download_failed');
        } finally {
            $this->busy = false;
            $this->refreshProfile();
            $this->reloadVersions(reset: true);
        }
    }

    // ------------------------------------------------------------- internals

    private function pullStep(Server $server, FileBridge $bridge, McJarsCatalogService $catalog, array $step): void
    {
        $url = $catalog->buildUrlOf($step);

        if ($url === null) {
            return;
        }

        // wings stores the artifact into / using its own server-side naming
        $bridge->pull($server, $url, '/');
    }

    private function removeStep(Server $server, FileBridge $bridge, string $location): void
    {
        $clean = ltrim(str_replace('\\', '/', trim($location)), '/');

        if ($clean === '' || str_contains($clean, '..')) {
            return;
        }

        $dir = dirname('/'.$clean);
        $name = basename($clean);

        if (in_array($name, ['.', '', '/'], true)) {
            return;
        }

        $bridge->delete($server, [$name], $dir === '/' ? '/' : rtrim($dir, '/'));
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

    /** @return list<array{name:string}> regular .jar entries in the root */
    private function jarRows(Server $server, FileBridge $bridge): array
    {
        $out = [];

        foreach ($bridge->safeLs($server, '/') as $row) {
            if (($row['file'] || $row['symlink'])
                && str_ends_with(mb_strtolower((string) $row['name']), '.jar')) {
                $out[] = ['name' => (string) $row['name']];
            }
        }

        return $out;
    }

    private function chmodSilently(Server $server, array $files): void
    {
        if ($files === []) {
            return;
        }

        if (! class_exists(DaemonFileRepository::class)) {
            return;
        }

        try {
            $repo = app(DaemonFileRepository::class);

            if (! method_exists($repo, 'chmodFiles')) {
                return;
            }

            $repo->setServer($server)->chmodFiles('/', $files);
        } catch (\Throwable) {
            // deliberately silent per contract ("defensive silent-catch")
        }
    }

    private function isRunning(Server $server): bool
    {
        try {
            return $server->retrieveStatus()->isStartingOrRunning();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Stops the container and waits up to ~30 s while defeating Server::retrieveStatus()'s
     * 15-second cache between polls (same cache key core writes).
     */
    private function stopAndAwaitOffline(Server $server): void
    {
        app(DaemonServerRepository::class)->setServer($server)->power('stop');

        for ($i = 0; $i < 30; $i++) {
            sleep(1);
            cache()->forget('servers.'.$server->uuid.'.status');

            try {
                if (! $server->retrieveStatus()->isStartingOrRunning()) {
                    return;
                }
            } catch (\Throwable) {
                // connection hiccup — keep polling until budget spent
            }
        }

        throw new StopTimedOut;
    }

    // --------------------------------------------------------------- loaders

    private function refreshProfile(): void
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        try {
            $this->profile = app(DetectionService::class)->detect($server);
        } catch (\Throwable) {
            $this->profile = null;
        }
    }

    private function loadTypes(): void
    {
        try {
            $types = app(McJarsCatalogService::class)->types();
        } catch (\Throwable) {
            $types = [];
        }

        $this->typeCache = $types;

        if (! isset($types[$this->bucket])) {
            $this->bucket = array_key_first($types) ?? 'recommended';
        }

        if ($this->type === '' || ! isset($types[$this->bucket][$this->type])) {
            $this->type = $this->installedTypeInBucket() ?? array_key_first($types[$this->bucket] ?? []);
        }

        $this->reloadVersions(reset: true);
    }

    private function installedTypeInBucket(): ?string
    {
        $software = mb_strtoupper((string) ($this->profile['software'] ?? ''));

        if ($software === '' || $software === 'UNKNOWN') {
            return null;
        }

        foreach ($this->typeCache[$this->bucket] ?? [] as $id => $_meta) {
            if (mb_strtoupper((string) $id) === $software) {
                return (string) $id;
            }
        }

        return null;
    }

    private function reloadVersions(bool $reset): void
    {
        if ($reset) {
            $this->page = 1;
            $this->total = 0;
        }

        $type = $this->type;

        if ($type === '') {
            $this->versions = [];
            $this->total = 0;

            return;
        }

        try {
            $snapshotsIn = $this->tab !== 'releases';
            $result = app(McJarsCatalogService::class)->versions(
                $type,
                page: $this->page,
                perPage: 50,
                search: trim($this->search),
                snapshotsIn: $snapshotsIn,
            );
        } catch (\Throwable) {
            $result = ['total' => 0, 'data' => []];
        }

        $rows = $result['data'];

        if ($this->tab === 'snapshots') {
            $rows = array_values(array_filter(
                $rows,
                fn (array $r): bool => in_array(($r['type'] ?? ''), ['SNAPSHOT', 'BETA'], true),
            ));
        }

        $this->versions = $reset ? $rows : array_merge($this->versions, $rows);
        $this->total = (int) ($result['total'] ?? count($this->versions));
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

        foreach ($this->typeCache[$this->bucket] ?? [] as $id => $meta) {
            $label = (string) ($meta['name'] ?? $id);
            $desc = filled($meta['description'] ?? null) ? (string) $meta['description'] : null;

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
        $software = mb_strtoupper((string) ($this->profile['software'] ?? ''));

        return $software !== ''
            && $software !== 'UNKNOWN'
            && mb_strtoupper($id) === $software;
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

    private function notifyGatewayDown(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::banner.failures.gateway_down'))
            ->danger()
            ->send();
    }

    /** @var array|null detection profile result cached during one render pass */
    public ?array $profile = null;
}

/**
 * Internal signal: the stop→offline wait burned its full 30 s budget.
 * Handled separately so we neither continue file IO nor auto-restart.
 */
final class StopTimedOut extends \RuntimeException {}
