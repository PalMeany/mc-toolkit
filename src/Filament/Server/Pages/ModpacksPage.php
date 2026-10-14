<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use McToolkit\Filament\Server\Pages\Concerns\InteractsWithContent;
use McToolkit\Filament\Server\Pages\Concerns\TracksServerTask;
use McToolkit\Jobs\InstallModpackJob;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\Registries\ModrinthService;
use McToolkit\Support\QueueMode;
use McToolkit\Support\ServerTask;
use Throwable;

/**
 * Modpacks — one-shot Modrinth `.mrpack` installer: mods, configs and the pack's loader.
 *
 * Icon: TablerIcon::Package ('tabler-package', verified app/Enums/TablerIcon.php:4324).
 *
 * Installs run in the background ({@see InstallModpackJob}): Install (or Update on a
 * pack row) opens a confirmation sheet with two options — also install the pack's
 * loader for its Minecraft version (default on; needs the Versions ability too) and
 * start the server afterwards (default off) — then the page reserves the server's task
 * slot and the task card ({@see TracksServerTask}) follows the run.
 *
 * Prerequisites:
 *  - The container MUST be fully stopped (Offline/Exited/Created). The page says so
 *    up-front, refuses to queue while it runs, and the installer re-checks right before
 *    moving files into place.
 *  - No blocker on Bukkit-family servers: a modpack IS the way to convert one. The
 *    sheet and the task notes say that the server switches to the pack's loader and
 *    that the current plugins stop loading.
 */
final class ModpacksPage extends Page
{
    use InteractsWithContent;
    use TracksServerTask;

    /** Verified case: app/Enums/TablerIcon.php:4324 = 'tabler-package'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Package;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 23;

    protected string $view = 'minecraft-toolkit::server.modpacks';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/modpacks (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/modpacks';

    /** Livewire event emitted by the shared detection-banner's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    /**
     * Confirmation sheet target (null = closed); only set server-side by openPackSheet():
     * id, title, is_update, version, loader, loader_label, game_version, matches, switches.
     */
    #[Locked]
    public ?array $pendingPack = null;

    /** Sheet option: install the pack's loader after its files (default on). */
    public bool $packInstallLoader = true;

    /** Sheet option: start the server once everything is in place (default off). */
    public bool $packStartAfter = false;

    /**
     * 'use' + 'content' on a server outside any conflict state. Filament re-runs this on
     * every Livewire request of the page (CanAuthorizeAccess::hydrateCanAuthorizeAccess).
     */
    public static function canAccess(): bool
    {
        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.modpacks', true)
            && static::canAccessContentCatalog();
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.modpacks.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(TablerIcon::Refresh)
                ->action(fn () => $this->refreshContent()),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Concern identity                                                   */
    /* ------------------------------------------------------------------ */

    public function contentKind(): string
    {
        return 'modpacks';
    }

    protected function installerKind(): string
    {
        return 'modpack';
    }

    protected function langSection(): string
    {
        return 'modpacks';
    }

    /**
     * Never blocked: with the loader installed along with the pack, a modpack is the way
     * to turn a Bukkit-family (or vanilla) server into a modded one. The confirmation
     * sheet warns that the current plugins stop loading instead.
     */
    public function blockedNotice(): ?array
    {
        return null;
    }

    /* ------------------------------------------------------------------ */
    /*  Install / update → confirmation sheet → queued job                 */
    /* ------------------------------------------------------------------ */

    /**
     * Browse-grid Install CTA (overrides the shared in-request install): opens the
     * confirmation sheet. Only ids travel; the title is resolved server-side.
     */
    public function install(string $source, string $projectId): void
    {
        $source = strtolower(trim($source));
        $projectId = trim($projectId);

        if (! $this->userCanMutate() || $source !== 'modrinth' || $projectId === '') {
            $this->denyNotification();

            return;
        }

        $this->openPackSheet($projectId, $this->catalogTitle($source, $projectId), false);
    }

    /**
     * Update button of a tracked pack row: same sheet, logged as an update. Registry,
     * project and title come from the database row, never from the client.
     */
    public function updateTracked(int $rowId): void
    {
        if (! $this->userCanMutate()) {
            $this->denyNotification();

            return;
        }

        $row = InstalledContent::query()
            ->forServer($this->contentServer())
            ->where('kind', InstalledContent::KIND_MODPACK)
            ->whereKey($rowId)
            ->first();

        if (! $row instanceof InstalledContent || $row->isPackChild() || ! $row->tracked_by_toolkit || $row->registry !== 'modrinth') {
            $this->denyNotification();

            return;
        }

        $title = $row->displayTitle() !== '' ? $row->displayTitle() : basename((string) $row->file_name);

        $this->openPackSheet((string) $row->project_id, $title, true);
    }

    public function closePack(): void
    {
        $this->pendingPack = null;
    }

    /** Queues the pack confirmed in the sheet. */
    public function confirmPack(): void
    {
        $pending = $this->pendingPack;
        $this->pendingPack = null;

        if (! is_array($pending) || ! is_string($pending['id'] ?? null) || ! $this->userCanMutate()) {
            $this->denyNotification();

            return;
        }

        $server = $this->contentServer();

        // The installer needs a stopped container; fail fast instead of queueing a run that
        // can only fail (it re-checks before moving files into place anyway).
        cache()->forget("servers.{$server->uuid}.status");

        if ($this->containerRequiresStop()) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.stopped_required'))
                ->body(trans('minecraft-toolkit::pages.versions.install.stopped_note'))
                ->warning()
                ->send();

            return;
        }

        $user = user();
        $title = (string) ($pending['title'] ?? $pending['id']);
        $token = ServerTask::start($server, ServerTask::KIND_MODPACK, $title, $user?->id);

        if ($token === null) {
            $this->notifyTaskBusy();
            $this->refreshTaskSnapshot();

            return;
        }

        try {
            QueueMode::dispatchLong(new InstallModpackJob(
                (int) $server->getKey(),
                (int) $user?->getKey(),
                $token,
                $pending['id'],
                $title,
                (bool) ($pending['is_update'] ?? false),
                $this->packInstallLoader,
                $this->packStartAfter,
            ));
        } catch (Throwable $e) {
            report($e);

            ServerTask::finish($server, $token, false, trans('minecraft-toolkit::pages.tasks.queue_failed'));
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.tasks.queue_failed'))
                ->danger()
                ->send();
            $this->refreshTaskSnapshot();

            return;
        }

        $this->notifyTaskQueued($title);
        $this->refreshTaskSnapshot();
    }

    /** Whether the user may also change the server software (the loader option). */
    public function canInstallLoader(): bool
    {
        $user = user();

        return $user !== null && InstallModpackJob::mayInstallLoader($this->contentServer(), $user);
    }

    private function openPackSheet(string $projectId, string $title, bool $isUpdate): void
    {
        $this->refreshTaskSnapshot();

        if ($this->taskIsActive()) {
            $this->notifyTaskBusy();

            return;
        }

        $preview = $this->installer()->modpackPreview($projectId);

        if ($preview === null) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.download_failed'))
                ->body(trans('minecraft-toolkit::pages.modpacks.confirm.no_archive'))
                ->danger()
                ->send();

            return;
        }

        if ($title === '' || $title === $projectId) {
            $title = $this->lookupPackTitle($projectId) ?? $projectId;
        }

        $loader = $preview['loader'];
        $detected = $this->contentDetection();
        $software = strtolower((string) ($detected['software'] ?? ''));
        $detectedVersion = is_string($detected['version'] ?? null) ? $detected['version'] : null;

        $this->packInstallLoader = true;
        $this->packStartAfter = false;
        $this->pendingPack = [
            'id' => $projectId,
            'title' => mb_substr($title, 0, 120),
            'is_update' => $isUpdate,
            'version' => $preview['version'],
            'loader' => $loader,
            'loader_label' => $loader !== null ? (InstallModpackJob::LOADER_NAMES[$loader] ?? ucfirst($loader)) : null,
            'game_version' => $preview['game_version'],
            'matches' => $loader !== null
                && ($software === $loader || ($loader === 'fabric' && $software === 'quilt'))
                && ($preview['game_version'] === null || $detectedVersion === $preview['game_version']),
            'switches' => $loader !== null && in_array((string) ($detected['loader'] ?? ''), ['bukkit', 'velocity'], true),
        ];
    }

    private function lookupPackTitle(string $projectId): ?string
    {
        try {
            $title = app(ModrinthService::class)->project($projectId)['title'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($title) && trim($title) !== '' ? trim($title) : null;
    }

    /* ------------------------------------------------------------------ */
    /*  Task card                                                          */
    /* ------------------------------------------------------------------ */

    protected function taskServer(): Server
    {
        return $this->contentServer();
    }

    public function canControlTask(): bool
    {
        return $this->userCanMutate();
    }

    /** A finished run changed the tracked rows and probably the software. */
    protected function afterTaskFinished(array $task): void
    {
        $this->forgetUpdateScan();
        $this->bustCaches();

        if (($task['kind'] ?? '') === ServerTask::KIND_MODPACK && ($task['state'] ?? '') === ServerTask::DONE) {
            $this->tab = 'installed';
        }
    }
}
