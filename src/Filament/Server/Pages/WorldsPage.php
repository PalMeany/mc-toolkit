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
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use McToolkit\Http\Controllers\WorldDownloadController;
use McToolkit\Services\DetectionService;
use McToolkit\Services\Properties\PropertiesParser;
use McToolkit\Services\Worlds\WorldsService;
use McToolkit\Support\Activity;

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
 * Authorization: page access needs `minecraft-toolkit.use` + `.worlds` on a server that
 * is not in a conflict state (re-checked by Filament on every Livewire request);
 * download / Play-on additionally need `.manage`, reset / import `.administer`, and
 * every mutation only targets folders the world listing itself returns.
 */
final class WorldsPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::World;

    protected static ?int $navigationSort = 26;

    protected string $view = 'minecraft-toolkit::server.worlds';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/worlds (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/worlds';

    /** Community-maps external target: observed Modrinth discover URL shape. */
    private const MAPS_BROWSE_URL = 'https://modrinth.com/discover/datapacks?q=world';

    /** @var list<array<string, mixed>> service-shaped world rows */
    #[Locked]
    public array $worlds = [];

    /** DetectionService::detect() payload feeding the pinned banner. */
    #[Locked]
    public array $detection = [];

    /**
     * Reset confirmation model: ['name' => string, 'paths' => list<string>].
     * Display only — doReset() re-derives the paths server-side.
     *
     * @var array{name:string,paths:list<string>}|null
     */
    #[Locked]
    public ?array $pendingReset = null;

    /**
     * Restart-suggestion callout state after Play-on / reset:
     * ['level' => target level-name].
     *
     * @var array{level:string}|null
     */
    #[Locked]
    public ?array $pendingRestart = null;

    /** Two-click arming guard for the suggested restart power action. */
    public bool $restartArmed = false;

    /* ------------------------------------------------------------------ */
    /*  Authorization                                                      */
    /* ------------------------------------------------------------------ */

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.worlds', true)
            && $tenant instanceof Server
            && ! $tenant->isInConflictState()
            && (bool) user()?->can('minecraft-toolkit.use', $tenant)
            && (bool) user()?->can('minecraft-toolkit.worlds', $tenant);
    }

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                          */
    /* ------------------------------------------------------------------ */

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

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

            // Import replaces/creates world folders: same strength as reset (administer).
            Action::make('import')
                ->label(trans('minecraft-toolkit::pages.worlds.import_link'))
                ->color('primary')
                ->icon(TablerIcon::CloudUpload)
                ->authorize(fn (): bool => $this->allows('administer'))
                ->schema([
                    TextInput::make('importUrl')
                        ->label(trans('minecraft-toolkit::pages.worlds.import_url'))
                        ->placeholder('https://example.com/world.zip')
                        ->url()
                        ->required()
                        ->maxLength(2048),
                    TextInput::make('importTargetName')
                        ->label(trans('minecraft-toolkit::pages.worlds.import_target'))
                        ->maxLength(100),
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
        } catch (\Throwable $exception) {
            report($exception);

            $this->detection = [];
        }

        try {
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
     * Signed redirect to WorldDownloadController, which compresses the world set and
     * forwards the browser to the node's own download endpoint.
     */
    public function downloadWorld(string $name): void
    {
        $server = $this->gate('manage');

        if (! $this->isKnownWorld($server, $name)) {
            return;
        }

        $this->redirect(route('mc-toolkit.download.world', [
            ...WorldDownloadController::makeQuery($server, $name),
            'uuid_short' => $server->uuid_short,
            'world' => $name,
        ]));
    }

    /** Opens the confirmation sheet listing every affected directory first. */
    public function confirmReset(string $name): void
    {
        $server = $this->gate('administer');

        try {
            $paths = app(WorldsService::class)->affectedPaths($server, $name);
        } catch (\Throwable $exception) {
            report($exception);

            $paths = [];
        }

        if ($paths === []) {
            $this->notifyUnknownWorld();

            return;
        }

        $this->pendingRestart = null;
        $this->pendingReset = ['name' => $name, 'paths' => array_values(array_map('strval', $paths))];
    }

    public function cancelReset(): void
    {
        $this->pendingReset = null;
    }

    public function doReset(): void
    {
        $target = $this->pendingReset;

        if ($target === null || strval($target['name'] ?? '') === '') {
            return;
        }

        $server = $this->gate('administer');
        $name = strval($target['name']);

        try {
            // Service re-validates the name against the listing and requires a stopped server.
            app(WorldsService::class)->reset($server, $name);
        } catch (\DomainException $exception) {
            $this->notifyFailure(trans('minecraft-toolkit::common.buttons.reset'), $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            report($exception);

            $this->notifyFailure(trans('minecraft-toolkit::common.buttons.reset'), trans('minecraft-toolkit::errors.statuses.wings_502'));

            return;
        }

        $this->pendingReset = null;
        $this->suggestRestart($name);
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

        $value = trim($name);

        if (! WorldsService::isSafeDirectoryName($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._\- ]*$/', $value) !== 1) {
            $this->notifyFailure(trans('minecraft-toolkit::common.buttons.save'), trans('minecraft-toolkit::pages.worlds.import_name_invalid'));

            return;
        }

        if (! $this->isKnownWorld($server, $value)) {
            return;
        }

        try {
            app(PropertiesParser::class)->write($server, ['level-name' => $value]);
        } catch (\Throwable $exception) {
            report($exception);

            $this->notifyFailure(trans('minecraft-toolkit::common.buttons.save'), trans('minecraft-toolkit::errors.statuses.wings_502'));

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
     * Schema-validated import payload handler. The service pulls into a staging folder,
     * never overwrites an existing world and needs the server stopped.
     */
    private function runImport(string $url, ?string $targetName): void
    {
        $server = $this->gate('administer');

        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            $this->notifyFailure(trans('minecraft-toolkit::pages.worlds.import_link'), trans('minecraft-toolkit::errors.statuses.download_failed'));

            return;
        }

        try {
            $imported = app(WorldsService::class)->import($server, $url, filled($targetName) ? $targetName : null);
        } catch (\DomainException $exception) {
            if ($exception->getPrevious() !== null) {
                report($exception->getPrevious());
            }

            $this->notifyFailure(trans('minecraft-toolkit::pages.worlds.import_link'), $exception->getMessage());

            return;
        } catch (\Throwable $exception) {
            report($exception);

            $this->notifyFailure(trans('minecraft-toolkit::pages.worlds.import_link'), trans('minecraft-toolkit::errors.statuses.download_failed'));

            return;
        }

        $this->loadData();

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.worlds.import_link'))
            ->body(trans('minecraft-toolkit::pages.worlds.import_done', ['world' => $imported]))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Suggested restart                                                  */
    /* ------------------------------------------------------------------ */

    /** Inline, two-click confirmed restart suggestion after level-name changes. */
    private function suggestRestart(string $levelName): void
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

    /** Permission probe for the view and action visibility (no abort). */
    public function allows(string $strength): bool
    {
        $server = $this->getRecord();

        return ! $server->isInConflictState()
            && (bool) user()?->can('minecraft-toolkit.use', $server)
            && (bool) user()?->can('minecraft-toolkit.worlds', $server)
            && (bool) user()?->can('minecraft-toolkit.'.$strength, $server);
    }

    /**
     * Central mutation gate — every mutating handler calls it before touching the
     * node so the permission matrix stays in ONE place.
     *
     * @param  'manage'|'administer'  $strength
     */
    private function gate(string $strength): Server
    {
        $server = $this->getRecord();

        abort_unless($this->allows($strength), 403);

        return $server;
    }

    /** Fresh (uncached) check that `$name` is a world row; notifies when it is not. */
    private function isKnownWorld(Server $server, string $name): bool
    {
        try {
            $known = in_array($name, app(WorldsService::class)->knownWorldNames($server), true);
        } catch (\Throwable $exception) {
            report($exception);

            $known = false;
        }

        if (! $known) {
            $this->notifyUnknownWorld();
        }

        return $known;
    }

    private function notifyUnknownWorld(): void
    {
        $this->notifyFailure(trans('minecraft-toolkit::pages.worlds.title'), trans('minecraft-toolkit::pages.worlds.unknown_world'));
    }

    private function notifyFailure(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();
    }

    public function getRecord(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
