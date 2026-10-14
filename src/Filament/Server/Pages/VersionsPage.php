<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use McToolkit\Filament\Server\Pages\Concerns\TracksServerTask;
use McToolkit\Jobs\InstallSoftwareJob;
use McToolkit\Services\Catalog\McJarsCatalogService;
use McToolkit\Services\Catalog\SoftwareInstallerService;
use McToolkit\Services\DetectionService;
use McToolkit\Support\Activity;
use McToolkit\Support\QueueMode;
use McToolkit\Support\ServerTask;

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
 * Installs run in the background: the page checks permissions, shows the confirmation
 * sheet and queues an {@see InstallSoftwareJob} (staged downloads, stop, swap, Java image
 * and startup alignment — see {@see SoftwareInstallerService}). The server's task card
 * ({@see TracksServerTask}) follows the run and refreshes detection when it ends.
 */
final class VersionsPage extends Page
{
    use TracksServerTask;

    protected static string|BackedEnum|null $navigationIcon = \App\Enums\TablerIcon::Stack2;

    protected static ?int $navigationSort = 20;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected string $view = 'minecraft-toolkit::server.versions';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/versions (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/versions';

    private const PER_PAGE = 50;

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

    /** True while the server has an active background task (install CTAs are disabled). */
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

    /**
     * Queues the install confirmed in the sheet. The work itself (downloads, stop, swap,
     * Java image / startup alignment, start) runs in {@see InstallSoftwareJob}; the page
     * only checks permissions, reserves the server's task slot and polls the tracker.
     */
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

        $user = user();
        $label = $this->catalogTypeName($type).' '.$version;

        // One task per server at a time (double clicks, second browser tab, a modpack run).
        $token = ServerTask::start($server, ServerTask::KIND_SOFTWARE, $label, $user?->id);

        if ($token === null) {
            $this->notifyTaskBusy();
            $this->refreshTaskSnapshot();

            return;
        }

        try {
            QueueMode::dispatchLong(new InstallSoftwareJob(
                (int) $server->getKey(),
                (int) $user?->getKey(),
                $token,
                $type,
                $version,
                is_int($pending['java'] ?? null) ? $pending['java'] : null,
                $this->backupFirst && $this->canBackup(),
                $this->startAfter,
            ));
        } catch (\Throwable $e) {
            report($e);

            ServerTask::finish($server, $token, false, trans('minecraft-toolkit::pages.tasks.queue_failed'));
            $this->notifyFailure('download_failed');
            $this->refreshTaskSnapshot();

            return;
        }

        $this->notifyTaskQueued($label);
        $this->refreshTaskSnapshot();
    }

    // ------------------------------------------------------------- task card

    protected function taskServer(): Server
    {
        return $this->server();
    }

    public function canControlTask(): bool
    {
        return $this->canManage($this->server());
    }

    protected function refreshTaskSnapshot(): void
    {
        try {
            $this->task = ServerTask::present($this->server());
        } catch (\Throwable) {
            $this->task = null;
        }

        $this->busy = ServerTask::isActive($this->task);
    }

    /** The swap changed the files: re-read detection (the job refreshed it) and the list. */
    protected function afterTaskFinished(array $task): void
    {
        $this->refreshProfile();
        $this->reloadVersions(reset: true);
    }

    /** Display name of a catalog type ("NeoForge"), falling back to "Neoforge". */
    private function catalogTypeName(string $type): string
    {
        foreach ($this->catalogTypes() as $types) {
            $name = $types[$type]['name'] ?? null;

            if (is_string($name) && trim($name) !== '') {
                return trim($name);
            }
        }

        return ucfirst(strtolower($type));
    }

    // ------------------------------------------------------------- internals

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
