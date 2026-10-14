<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\SubuserPermission;
use App\Enums\TablerIcon;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Livewire\Attributes\On;
use McToolkit\Http\Controllers\WorldDownloadController;
use McToolkit\Services\DetectionService;
use McToolkit\Services\Properties\PropertiesParser;
use McToolkit\Support\Activity;
use McToolkit\Services\Worlds\WorldsService;

/**
 * Worlds — dimension-grouped listing with download / reset / import and the
 * Play-on-another-world switch.
 *
 * Parity anchors (demo recon §Worlds):
 *  - subheading verbatim from lang pages.worlds.subheading;
 *  - detection banner pinned above everything (global toolkit pattern);
 *  - rows: bold name + Default chip on the active world + dimension chip
 *    (Overworld/Nether/End) + one middot-separated tertiary meta line
 *    (approx. size · Minecraft <ver> · mode · Played <date> · seed mono-clipped);
 *  - per-row actions Download / Reset (+ Play-on secondary for non-default);
 *  - community maps header action opening Modrinth externally.
 *
 * All interactivity is server-side Livewire state + wire:click handlers per the
 * build-contract UI conventions; no bespoke SPA routing, no polling.
 */
final class WorldsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?int $navigationSort = 26;

    protected string $view = 'minecraft-toolkit::server.worlds';

    /** Community-maps external target: observed Modrinth discover URL shape. */
    private const MAPS_BROWSE_URL = 'https://modrinth.com/discover/datapacks?q=world';

    /** @var list<array<string, mixed>> service-shaped world rows */
    public array $worlds = [];

    /** DetectionService::detect() payload feeding the pinned banner. */
    public array $detection = [];

    /**
     * Reset confirmation model: ['name' => string, 'paths' => list<string>].
     *
     * @var array{name:string,paths:list<string>}|null
     */
    public ?array $pendingReset = null;

    /**
     * Restart-suggestion callout state after Play-on / reset:
     * ['level' => target level-name].
     *
     * @var array{level:string}|null
     */
    public ?array $pendingRestart = null;

    /** Two-click arming guard for the suggested restart power action. */
    public bool $restartArmed = false;

    /* ------------------------------------------------------------------ */
    /*  Authorization                                                      */
    /* ------------------------------------------------------------------ */

    public static function canAccess(): bool
    {
        // Admin "Enabled pages" toggle (same guard every other toolkit page applies).
        if (! (bool) config('minecraft-toolkit.pages.enabled.worlds', true)) {
            return false;
        }

        return parent::canAccess()
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        // Contract matrix: worlds domain gate (read surface).
        abort_unless(user()?->can('minecraft-toolkit.worlds', Filament::getTenant()), 403);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                          */
    /* ------------------------------------------------------------------ */

    public function mount(): void
    {
        $this->authorizeAccess();

        $this->loadData();
    }

    /* ------------------------------------------------------------------ */
    /*  Header text                                                        */
    /* ------------------------------------------------------------------ */

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.worlds.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.worlds.title');
    }

    /** Group label rides the shared nav key so the sidebar string stays translatable. */
    public static function getNavigationGroup(): ?string
    {
        return trans('minecraft-toolkit::common.nav.group_label');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.worlds.subheading');
    }

    /* ------------------------------------------------------------------ */
    /*  Header actions                                                     */
    /* ------------------------------------------------------------------ */

    protected function getHeaderActions(): array
    {
        $server = $this->getRecord();

        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->color('gray')
                ->icon(TablerIcon::Refresh)
                ->action(fn () => $this->loadData()),

            Action::make('mapsBrowse')
                ->label(trans('minecraft-toolkit::pages.worlds.maps_browse'))
                ->color('gray')
                ->icon(TablerIcon::ExternalLink)
                ->url(self::MAPS_BROWSE_URL)
                ->openUrlInNewTab(true),

            // Import runs through a mounted Filament Action modal (IMPLEMENTATION-GUIDE
            // §2.4: schema-backed fields replace the previous bespoke fixed dialog).
            Action::make('import')
                ->label(trans('minecraft-toolkit::pages.worlds.import_link'))
                ->color('primary')
                ->icon(TablerIcon::CloudUpload)
                ->authorize(fn () => user()?->can('minecraft-toolkit.worlds', $server) && user()?->can('minecraft-toolkit.manage', $server))
                ->schema([
                    TextInput::make('importUrl')
                        ->label('Archive URL (.zip)')
                        ->placeholder('https://example.com/world.zip')
                        ->url()
                        ->required()
                        ->maxLength(2048),
                    TextInput::make('importTargetName')
                        ->label('Name of the imported world (optional)'),
                ])
                ->modalSubmitActionLabel(__('minecraft-toolkit::common.buttons.import'))
                ->action(function (array $data): void {
                    $this->runImport(
                        trim(strval($data['importUrl'] ?? '')),
                        filled($data['importTargetName'] ?? null) ? trim(strval($data['importTargetName'])) : null,
                    );
                }),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Data loading                                                       */
    /* ------------------------------------------------------------------ */

    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        $server = $this->getRecord();

        try {
            $this->detection = app(DetectionService::class)->detect($server, true);

            Activity::event('mc-toolkit:detection.refresh')->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->loadData();
    }

    private function loadData(): void
    {
        $server = $this->getRecord();

        try {
            $this->detection = app(DetectionService::class)->detect($server);
            $this->worlds = app(WorldsService::class)->listWorlds($server);
        } catch (\Throwable $exception) {
            report($exception);

            $this->worlds = [];

            Notification::make()
                ->title(trans('minecraft-toolkit::pages.worlds.title'))
                ->body(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Row actions                                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Signed direct-download redirect; WorldDownloadController owns the exact
     * `world` + `token` query contract and re-checks worlds+manage itself.
     */
    public function downloadWorld(string $name): void
    {
        $server = $this->gate('manage');

        $url = route(
            'mc-toolkit.download.world',
            [...WorldDownloadController::makeQuery($server, basename($name)), 'uuid_short' => $server->uuid_short, 'world' => basename($name)],
        );

        $this->redirect($url);
    }

    /** Opens the confirmation sheet listing every affected directory first. */
    public function confirmReset(string $name): void
    {
        $server = $this->gate('administer');

        try {
            $paths = app(WorldsService::class)->affectedPaths($server, $name);
        } catch (\Throwable) {
            $paths = [basename($name)];
        }

        if ($paths === []) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.reset'))
                ->body(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        $this->pendingRestart = null;
        $this->pendingReset = ['name' => basename($name), 'paths' => array_values(array_map('strval', $paths))];
    }

    public function cancelReset(): void
    {
        $this->pendingReset = null;
    }

    public function doReset(): void
    {
        $target = $this->pendingReset;

        if ($target === null || ($target['name'] ?? '') === '') {
            return;
        }

        $this->gate('administer');

        $server = $this->getRecord();

        try {
            app(WorldsService::class)->reset($server, strval($target['name']));
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.reset'))
                ->body(trans('minecraft-toolkit::errors.statuses.wings_502'))
                ->danger()
                ->send();

            return;
        }

        $this->pendingReset = null;
        $this->suggestRestart(strval($target['name']));
        $this->loadData();

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.reset'))
            ->success()
            ->send();
    }

    /** Switches the active world by writing level-name into server.properties. */
    public function playOn(string $name): void
    {
        $server = $this->gate('manage');

        $value = basename(trim($name));

        if ($value === '' || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\- ]*$/', $value) !== 1) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        try {
            app(PropertiesParser::class)->write($server, ['level-name' => $value]);
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.save'))
                ->body(trans('minecraft-toolkit::errors.statuses.wings_502'))
                ->danger()
                ->send();

            return;
        }

        Activity::event('mc-toolkit:properties.save')
            ->subject($server)
            ->property(['changed' => 'level-name'])
            ->log();

        $this->suggestRestart($value);

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.save'))
            ->success()
            ->send();

        $this->loadData();
    }

    /* ------------------------------------------------------------------ */
    /*  Import (mounted `import` Action modal)                             */
    /* ------------------------------------------------------------------ */

    /**
     * Schema-validated import payload handler; carries over the historic
     * submitImport() behavior minus the bespoke dialog plumbing.
     */
    private function runImport(string $url, ?string $targetName): void
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.worlds.import_link'))
                ->body(trans('minecraft-toolkit::errors.statuses.download_failed'))
                ->danger()
                ->send();

            return;
        }

        $server = $this->gate('administer');

        try {
            app(WorldsService::class)->import(
                $server,
                $url,
                filled($targetName) ? $targetName : null,
            );
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('minecraft-toolkit::pages.worlds.import_link'))
                ->body(filled($exception->getMessage()) ? $exception->getMessage() : trans('minecraft-toolkit::errors.statuses.download_failed'))
                ->danger()
                ->send();

            return;
        }

        $this->loadData();

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.worlds.import_link'))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Suggested restart                                                  */
    /* ------------------------------------------------------------------ */

    /** Inline, two-click confirmed restart suggestion after level-name changes. */
    public function suggestRestart(string $levelName): void
    {
        $this->pendingRestart = ['level' => $levelName];
        $this->restartArmed = false;
    }

    public function dismissRestart(): void
    {
        $this->pendingRestart = null;
        $this->restartArmed = false;
    }

    public function armRestart(): void
    {
        $this->restartArmed = true;
    }

    public function doRestart(): void
    {
        $server = $this->getRecord();

        abort_unless(user()?->can(SubuserPermission::ControlRestart, $server), 403);
        abort_if($server->isInConflictState(), 403);

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');

            cache()->forget("servers.$server->uuid.status");
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->body(trans('exceptions.node.error_connecting', ['node' => $server->node?->name ?? '?']))
                ->danger()
                ->send();

            return;
        }

        $this->pendingRestart = null;
        $this->restartArmed = false;

        Notification::make()
            ->title(trans('server/console.power_actions.restart'))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  View helpers                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Central mutation gate — every mutating handler calls it before touching the
     * node so the permission matrix stays in ONE place.
     *
     * @param  'manage'|'administer'  $strength
     */
    private function gate(string $strength): Server
    {
        $server = $this->getRecord();

        abort_unless(user()?->can('minecraft-toolkit.worlds', $server), 403);
        abort_unless(user()?->can('minecraft-toolkit.'.$strength, $server), 403);

        return $server;
    }

    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
