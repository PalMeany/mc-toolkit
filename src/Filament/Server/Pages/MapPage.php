<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Facades\Activity;
use App\Models\Server;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use McToolkit\Models\MapMarker;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Maps\MapTileService;
use McToolkit\Services\Worlds\WorldsService;
use McToolkit\Support\WorldLayout;
use UnitEnum;

/**
 * Top-down block map of the current server worlds.
 *
 * Live demo-parity surface (see /.research/demo-findings.md §Map): left control stack
 * (map switcher, layer preset toggles, persisted markers CRUD, player panel),
 * main canvas driven by the inline ES2020 script in resources/views/server/map.blade.php,
 * header Redraw control plus the shared filesystem-detection banner.
 *
 * Map TARGETS: one entry per (world folder, dimension) the world listing can draw —
 * `world` (overworld), `world@nether`, `world@end` for a vanilla layout and for every
 * Minecraft 26.1+ world (Paper included: one folder, `dimensions/minecraft/*`), or
 * `world`, `world_nether@nether`, `world_the_end@end` for pre-26 Paper split worlds.
 * The target list and the selection are #[Locked]: clients pick through selectWorld().
 *
 * Backend facts kept server-side here:
 * - which regions exist (MapTileService::availableRegionsWithMeta), nearest to spawn first,
 * - map targets + spawn coordinates (WorldsService::listWorlds),
 * - companion-bridge player positions/time (.toolkit/map.json; ABSENT bridges simply
 *   hide pins behind an explanatory placeholder chip rather than erroring),
 * - persisted markers (mc_map_markers, per world + dimension, gated on map + manage).
 */
final class MapPage extends Page
{
    /* ------------------------------------------------------------------ */
    /*  Navigation / shell                                                 */
    /* ------------------------------------------------------------------ */

    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Map;

    protected static ?int $navigationSort = 27;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected string $view = 'minecraft-toolkit::server.map';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/map (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/map';

    /** Client-freshness epoch appended to every tile request URL (Redraw bumps it). */
    #[Locked]
    public int $tileEpoch = 1;

    /* ------------------------------------------------------------------ */
    /*  Interactive state                                                  */
    /* ------------------------------------------------------------------ */

    /** Key of the selected map target (`<folder>` or `<folder>@<dimension>`). */
    #[Locked]
    public string $selectedWorld = '';

    /**
     * Map targets fed to the radio list.
     *
     * @var list<array{key:string, name:string, dimension:string, default:bool, title:?string, spawn_x:?int, spawn_z:?int}>
     */
    #[Locked]
    public array $worlds = [];

    /**
     * Regions known for the selected target (`rx`,`rz`,`mtime`,`size`) + truncated flag.
     *
     * @var array{regions:list<array{rx:int, rz:int, mtime:int, size:int}>, truncated:bool, total:int}
     */
    #[Locked]
    public array $regionMeta = ['regions' => [], 'truncated' => false, 'total' => 0];

    /**
     * Markers of the selected target: id,label,x,z,color.
     *
     * @var list<array{id:int, label:string, x:int, z:int, color:?string}>
     */
    #[Locked]
    public array $markers = [];

    /** Toggle: follow mode recenters the viewport every ping onto the lead pin. */
    public bool $follow = false;

    /** Layer presets drawn client-side only (Spawn / World border annotation overlays). */
    public bool $showSpawn = true;

    public bool $showWorldBorder = false;

    /**
     * Companion-bridge pins, normalized from `.toolkit/map.json`: name,x,y,z,dimension,world.
     *
     * @var list<array{name:string, x:int|float|null, y:int|float|null, z:int|float|null, dimension:string, world:?string}>
     */
    #[Locked]
    public array $pins = [];

    /** In-game day clock ticks from the bridge; null renders dashes client-side. */
    #[Locked]
    public ?int $gameTimeTicks = null;

    /** Detection summary snapshot powering the pinned banner above the canvas. */
    #[Locked]
    public array $software = [];

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                          */
    /* ------------------------------------------------------------------ */

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $server = $this->server();

        try {
            $this->software = app(DetectionService::class)->detect($server);
        } catch (\Throwable $exception) {
            report($exception);
        }

        $this->loadWorlds();

        $this->selectedWorld = $this->resolveDefaultWorld();

        $this->loadRegionMeta();
        $this->loadMarkers();

        // Silent first hydration of bridge pins/time so the canvas paints instantly.
        $this->refreshBridge();
    }

    public static function canAccess(): bool
    {
        $tenant = Filament::getTenant();

        return parent::canAccess()
            && (bool) config('minecraft-toolkit.pages.enabled.map', true)
            && $tenant instanceof Server
            && ! $tenant->isInConflictState()
            && (bool) user()?->can('minecraft-toolkit.use', $tenant)
            && (bool) user()?->can('minecraft-toolkit.map', $tenant);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.map.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.map.subheading');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.map.title');
    }

    /**
     * Header controls; parity keeps exactly one destructive-free global: Redraw.
     * Marker creation is a mounted Filament Action modal — triggerable from the
     * markers card.
     *
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('redraw')
                ->label(trans('minecraft-toolkit::pages.map.redraw'))
                ->icon(TablerIcon::Refresh)
                ->color('gray')
                ->action(fn () => $this->redraw()),

            Action::make('add_marker')
                ->label(trans('minecraft-toolkit::pages.map.layers.markers'))
                ->color('gray')
                ->authorize(fn (): bool => $this->canManageMarkers())
                ->schema([
                    TextInput::make('newLabel')
                        ->label(trans('minecraft-toolkit::pages.map.layers.markers'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('newX')
                        ->label('X')
                        ->integer()
                        ->minValue(-30_000_000)
                        ->maxValue(30_000_000)
                        ->required(),
                    TextInput::make('newZ')
                        ->label('Z')
                        ->integer()
                        ->minValue(-30_000_000)
                        ->maxValue(30_000_000)
                        ->required(),
                    ColorPicker::make('newColor')
                        ->default('#fbbf24'),
                ])
                ->action(fn (array $data) => $this->createMarker($data)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Bridge / detection listeners                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Companion mod feed poll target (12s cadence from the blade). Reads
     * `.toolkit/map.json`; silence is deliberate — the pins panel explains its own
     * absence state visually instead of toasting every 12 seconds.
     *
     * Accepted document shapes (tolerant, bridges are third-party artifacts):
     *   {"players":[{name,x,y,z,dimension?,world?}], "time":12345}
     *   {"players":{"Steve":{x,y,z,dimension?,world?}}, "time":12345}
     *   [{name,x,y,z}, ...]
     * A missing `dimension` means overworld; a missing `world` matches every world.
     */
    public function refreshBridge(): void
    {
        [$allPins, $ticks] = $this->readBridgeState($this->server());

        $dimension = $this->currentDimension();
        $folder = $this->selectedFolder();

        $this->pins = array_values(array_filter(
            $allPins,
            static fn (array $pin): bool => $pin['dimension'] === $dimension
                && self::pinBelongsToFolder($pin['world'], $folder, $dimension),
        ));
        $this->gameTimeTicks = $ticks;
    }

    /**
     * Whether a bridge pin's `world` belongs to the selected folder: no world, the exact
     * folder name, or — for the nether/end — the Bukkit level name `<folder>_nether` /
     * `<folder>_the_end`. Paper 26.x keeps those names for its nether/end although their
     * files now live inside `<folder>/dimensions/minecraft/`.
     */
    private static function pinBelongsToFolder(?string $world, string $folder, string $dimension): bool
    {
        if ($world === null || $world === $folder) {
            return true;
        }

        $suffix = ['nether' => '_nether', 'end' => '_the_end'][$dimension] ?? null;

        return $suffix !== null && $world === $folder.$suffix;
    }

    /**
     * Forces a fresh filesystem detection pass (wired by the shared banner component
     * event `mc-toolkit.redetect`), then announces the outcome.
     */
    #[On('mc-toolkit.redetect')]
    public function redetect(): void
    {
        $server = $this->server();

        try {
            $this->software = app(DetectionService::class)->detect($server, force: true);

            Activity::event('mc-toolkit:detection.refresh')->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->body(strip_tags(trans('minecraft-toolkit::banner.detection.title_note')))
                ->success()
                ->send();
        } catch (\Throwable $exception) {
            report($exception);

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->body(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Viewport / world actions                                           */
    /* ------------------------------------------------------------------ */

    public function selectWorld(string $key): void
    {
        if ($this->targetByKey($key) === null) {
            return; // unknown key - keep current selection untouched.
        }

        $this->selectedWorld = $key;

        $this->loadRegionMeta();
        $this->loadMarkers();

        $this->refreshBridge();
    }

    public function toggleFollow(): void
    {
        $this->follow = ! $this->follow;
    }

    public function togglePreset(string $preset): void
    {
        match ($preset) {
            'spawn' => $this->showSpawn = ! $this->showSpawn,
            'border' => $this->showWorldBorder = ! $this->showWorldBorder,
            default => null,
        };
    }

    /**
     * REDRAW parity control: purges the LOCAL `.mca` mirror of the selected target,
     * moves its render generation forward (fresh server-side pixels), re-lists its
     * regions and bumps the client epoch so the JS scheduler drops its tiles too.
     */
    public function redraw(): void
    {
        $target = $this->targetByKey($this->selectedWorld);

        if ($target !== null) {
            try {
                app(MapTileService::class)->invalidate($this->server(), $target['name'], $target['dimension']);
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $this->loadRegionMeta();
        $this->tileEpoch++;

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.map.redraw'))
            ->body(strip_tags(trans('minecraft-toolkit::pages.map.render_note', ['world' => $this->selectedLabel()])))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Markers CRUD                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Persists one marker from the mounted `add_marker` Action schema for the selected
     * world folder + dimension; the mutation gate stays map domain + manage.
     *
     * @param  array{newLabel?:mixed, newX?:mixed, newZ?:mixed, newColor?:mixed}  $data
     */
    public function createMarker(array $data): void
    {
        $server = $this->server();

        if (! $this->canMutateMarkers($server)) {
            $this->notifyDenied();

            return;
        }

        $label = mb_substr(trim(strval($data['newLabel'] ?? '')), 0, 255);
        $x = filter_var($data['newX'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -30_000_000, 'max_range' => 30_000_000]]);
        $z = filter_var($data['newZ'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => -30_000_000, 'max_range' => 30_000_000]]);
        $target = $this->targetByKey($this->selectedWorld);

        if ($label === '' || ! is_int($x) || ! is_int($z) || $target === null) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.map.layers.markers'))
                ->body(trans('minecraft-toolkit::common.buttons.no_results'))
                ->danger()
                ->send();

            return;
        }

        $attributes = [
            'server_id' => $server->id,
            'label' => $label,
            'x' => $x,
            'z' => $z,
            'dimension' => $target['dimension'],
            'color' => $this->sanitizedHexColor(strval($data['newColor'] ?? '')),
        ];

        // Column is varchar(191); a longer folder name falls back to "every world".
        if (MapMarker::supportsWorldColumn() && mb_strlen($target['name']) <= 191) {
            $attributes['world'] = $target['name'];
        }

        MapMarker::query()->create($attributes);

        Activity::event('mc-toolkit:map.marker.create')
            ->subject($server)
            ->property([
                'label' => $label,
                'x' => $x,
                'z' => $z,
            ])
            ->log();

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.save'))
            ->body(strip_tags(trans(
                'minecraft-toolkit::activity.mc-toolkit.map.marker.create',
                ['label' => $label, 'x' => $x, 'z' => $z],
            )))
            ->success()
            ->send();

        $this->loadMarkers();
    }

    /**
     * Removes one marker owned by this server and visible on the selected target;
     * same mutation gates as creation.
     */
    public function deleteMarker(int $markerId): void
    {
        $server = $this->server();

        if (! $this->canMutateMarkers($server)) {
            $this->notifyDenied();

            return;
        }

        $target = $this->targetByKey($this->selectedWorld);

        if ($target === null) {
            return;
        }

        /** @var MapMarker|null $marker */
        $marker = MapMarker::query()
            ->whereKey($markerId)
            ->forServer($server)
            ->dimension($target['dimension'])
            ->forWorld($target['name'])
            ->first();

        if ($marker === null) {
            return;
        }

        $payload = ['label' => $marker->label, 'x' => $marker->x, 'z' => $marker->z];

        $marker->delete();

        Activity::event('mc-toolkit:map.marker.delete')
            ->subject($server)
            ->property($payload)
            ->log();

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.map.layers.markers'))
            ->body(strip_tags(trans(
                'minecraft-toolkit::activity.mc-toolkit.map.marker.delete',
                ['label' => $payload['label']],
            )))
            ->success()
            ->send();

        $this->loadMarkers();
    }

    /** Accepts only canonical #rrggbb hex (lowercased); anything else persists as null. */
    private function sanitizedHexColor(string $raw): ?string
    {
        $clean = trim($raw);

        return preg_match('/^#[0-9a-fA-F]{6}$/', $clean) === 1 ? strtolower($clean) : null;
    }

    private function canMutateMarkers(Server $server): bool
    {
        return ! $server->isInConflictState()
            && (bool) user()?->can('minecraft-toolkit.use', $server)
            && (bool) user()?->can('minecraft-toolkit.map', $server)
            && (bool) user()?->can('minecraft-toolkit.manage', $server);
    }

    private function notifyDenied(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
            ->danger()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Data hydration                                                     */
    /* ------------------------------------------------------------------ */

    /**
     * Fills $worlds. Deliberately NOT named hydrateWorlds(): Livewire calls hydrate{Property}()
     * on every request for each public property, and a private method of that name makes
     * every update of this page throw (BadMethodCallException from Component::__call).
     */
    private function loadWorlds(): void
    {
        $server = $this->server();
        $targets = [];

        try {
            foreach (app(WorldsService::class)->listWorlds($server, false) as $row) {
                if (! is_array($row) || ! is_string($row['name'] ?? null) || ! WorldsService::isSafeDirectoryName($row['name'])) {
                    continue;
                }

                foreach ((array) ($row['maps'] ?? []) as $dimension) {
                    if (! in_array($dimension, MapTileService::DIMENSIONS, true)) {
                        continue;
                    }

                    // Spawn coordinates belong to the overworld; other dimensions start at 0,0.
                    $isOverworld = $dimension === 'overworld';

                    $targets[] = [
                        'key' => $isOverworld ? $row['name'] : $row['name'].'@'.$dimension,
                        'name' => $row['name'],
                        'dimension' => $dimension,
                        'default' => $isOverworld && (bool) ($row['default'] ?? false),
                        'title' => is_string($row['title'] ?? null) ? $row['title'] : null,
                        'spawn_x' => $isOverworld && is_int($row['spawn']['x'] ?? null) ? $row['spawn']['x'] : null,
                        'spawn_z' => $isOverworld && is_int($row['spawn']['z'] ?? null) ? $row['spawn']['z'] : null,
                    ];
                }
            }
        } catch (\Throwable $exception) {
            report($exception);

            $targets = [];
        }

        if ($targets === []) {
            $targets = $this->fallbackWorldScan($server);
        }

        $this->worlds = $targets;
    }

    /**
     * Degraded overworld-only scan used when the world listing failed: root folders
     * (conventional `world*` names first) that hold an overworld region folder — 26.1+
     * `dimensions/minecraft/overworld/region` or classic `region`.
     *
     * @return list<array{key:string, name:string, dimension:string, default:bool, title:?string, spawn_x:?int, spawn_z:?int}>
     */
    private function fallbackWorldScan(Server $server): array
    {
        $bridge = $this->bridge();
        $rootDirs = [];

        try {
            foreach ($bridge->ls($server, '/') as $entry) {
                $name = strval($entry['name']);

                if (($entry['directory'] ?? false) === true && WorldsService::isSafeDirectoryName($name)) {
                    $rootDirs[] = $name;
                }
            }
        } catch (\Throwable) {
            return [];
        }

        usort($rootDirs, static fn (string $a, string $b): int => [str_starts_with($a, 'world') ? 0 : 1, $a] <=> [str_starts_with($b, 'world') ? 0 : 1, $b]);

        $found = [];

        foreach (array_slice($rootDirs, 0, 8) as $dir) {
            $hasOverworld = false;

            foreach (WorldLayout::REGION_CANDIDATES['overworld'] as $suffix) {
                if ($bridge->exists($server, $dir.'/'.$suffix)) {
                    $hasOverworld = true;

                    break;
                }
            }

            if ($hasOverworld) {
                $found[] = [
                    'key' => $dir,
                    'name' => $dir,
                    'dimension' => 'overworld',
                    'default' => false,
                    'title' => null,
                    'spawn_x' => null,
                    'spawn_z' => null,
                ];
            }
        }

        return $found;
    }

    private function resolveDefaultWorld(): string
    {
        $targets = collect($this->worlds);

        // Priority 1: explicit default flag from the world listing.
        $explicit = $targets->firstWhere('default', true);

        if (is_array($explicit)) {
            return strval($explicit['key']);
        }

        // Priority 2: the level-name declared inside server.properties.
        $declared = $this->declaredLevelName($this->server()) ?? 'world';
        $declaredTarget = $targets->first(static fn (array $t): bool => $t['name'] === $declared && $t['dimension'] === 'overworld');

        if (is_array($declaredTarget)) {
            return strval($declaredTarget['key']);
        }

        return strval($targets->value('key') ?? '');
    }

    /** Reads the vanilla `level-name=` entry straight from server.properties (bounded read). */
    private function declaredLevelName(Server $server): ?string
    {
        try {
            $raw = $this->bridge()->read($server, '/server.properties', 32_768);
        } catch (\Throwable) {
            return null;
        }

        if ($raw === null || preg_match('/^level-name[ \t]*[=:][ \t]*(.+)$/m', $raw, $m) !== 1) {
            return null;
        }

        $value = trim(strval($m[1]));

        return $value === '' ? null : $value;
    }

    private function loadRegionMeta(): void
    {
        $target = $this->targetByKey($this->selectedWorld);

        if ($target === null) {
            $this->regionMeta = ['regions' => [], 'truncated' => false, 'total' => 0];

            return;
        }

        try {
            $this->regionMeta = app(MapTileService::class)->availableRegionsWithMeta(
                $this->server(),
                $target['name'],
                $target['dimension'],
                $target['spawn_x'],
                $target['spawn_z'],
            );
        } catch (\Throwable $exception) {
            report($exception);

            $this->regionMeta = ['regions' => [], 'truncated' => false, 'total' => 0];
        }
    }

    private function loadMarkers(): void
    {
        $target = $this->targetByKey($this->selectedWorld);

        if ($target === null) {
            $this->markers = [];

            return;
        }

        try {
            /** @var Collection<int, MapMarker> $markers */
            $markers = MapMarker::query()
                ->forServer($this->server())
                ->dimension($target['dimension'])
                ->forWorld($target['name'])
                ->orderBy('id')
                ->get(['id', 'label', 'x', 'z', 'color']);

            $this->markers = $markers
                ->map(static fn (MapMarker $marker): array => [
                    'id' => (int) $marker->getKey(),
                    'label' => (string) $marker->label,
                    'x' => (int) $marker->x,
                    'z' => (int) $marker->z,
                    'color' => $marker->color,
                ])
                ->values()
                ->all();
        } catch (\Throwable $exception) {
            report($exception);

            $this->markers = [];
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Helper plumbing                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Normalizes whatever `.toolkit/map.json` says into [pins, ticks].
     *
     * @return array{0:list<array{name:string, x:int|float|null, y:int|float|null, z:int|float|null, dimension:string, world:?string}>, 1:?int}
     */
    private function readBridgeState(Server $server): array
    {
        $path = trim((string) config('minecraft-toolkit.bridge.path', '.toolkit'), '/').'/map.json';

        try {
            $raw = $this->bridge()->read($server, '/'.$path, 65_536);
        } catch (\Throwable) {
            return [[], null];
        }

        if ($raw === null || trim($raw) === '') {
            return [[], null];
        }

        try {
            $document = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [[], null]; // corrupt half-write: treat as absent, poll retries later.
        }

        if (! is_array($document)) {
            return [[], null];
        }

        $playersRaw = $document['players'] ?? (array_is_list($document) ? $document : []);

        $pins = [];

        $pushPin = static function (string $name, mixed $body) use (&$pins): void {
            if (! is_array($body)) {
                return;
            }

            $dimension = strtolower(trim(strval(is_scalar($body['dimension'] ?? null) ? $body['dimension'] : 'overworld')));
            $dimension = match ($dimension) {
                'minecraft:overworld' => 'overworld',
                'minecraft:the_nether', 'the_nether' => 'nether',
                'minecraft:the_end', 'the_end' => 'end',
                default => $dimension,
            };

            $world = is_string($body['world'] ?? null) && $body['world'] !== '' ? $body['world'] : null;

            $pins[] = [
                'name' => mb_scrub(mb_substr(trim($name), 0, 64), 'UTF-8'),
                'x' => is_int($body['x'] ?? null) || is_float($body['x'] ?? null) ? $body['x'] : null,
                'y' => is_int($body['y'] ?? null) || is_float($body['y'] ?? null) ? $body['y'] : null,
                'z' => is_int($body['z'] ?? null) || is_float($body['z'] ?? null) ? $body['z'] : null,
                'dimension' => in_array($dimension, MapTileService::DIMENSIONS, true) ? $dimension : 'overworld',
                'world' => $world,
            ];
        };

        if (is_array($playersRaw)) {
            if (array_is_list($playersRaw)) {
                foreach ($playersRaw as $entry) {
                    if (is_array($entry) && isset($entry['name']) && is_string($entry['name'])) {
                        $pushPin($entry['name'], $entry);
                    }
                }
            } else {
                foreach ($playersRaw as $name => $body) {
                    if (is_string($name) && $name !== '') {
                        $pushPin($name, $body);
                    }
                }
            }
        }

        $tickCandidates = [$document['time'] ?? null, $document['world_time'] ?? null, $document['dayTime'] ?? null];

        $ticks = null;

        foreach ($tickCandidates as $candidate) {
            if (is_int($candidate) || is_float($candidate)) {
                $ticks = (int) round((float) $candidate);

                break;
            }
        }

        return [$pins, $ticks];
    }

    /**
     * @return array{key:string, name:string, dimension:string, default:bool, title:?string, spawn_x:?int, spawn_z:?int}|null
     */
    private function targetByKey(string $key): ?array
    {
        if ($key === '') {
            return null;
        }

        foreach ($this->worlds as $target) {
            if (($target['key'] ?? null) === $key) {
                return $target;
            }
        }

        return null;
    }

    /** World folder of the selected target ('' when none). */
    public function selectedFolder(): string
    {
        return strval($this->targetByKey($this->selectedWorld)['name'] ?? '');
    }

    /** Dimension of the selected target; drives marker scoping AND which bridge pins surface. */
    public function currentDimension(): string
    {
        return strval($this->targetByKey($this->selectedWorld)['dimension'] ?? 'overworld');
    }

    /** Human label of the selected target: "world", "world · Nether"… */
    public function selectedLabel(): string
    {
        $target = $this->targetByKey($this->selectedWorld);

        if ($target === null) {
            return '';
        }

        return $target['dimension'] === 'overworld'
            ? $target['name']
            : $target['name'].' · '.trans('minecraft-toolkit::pages.map.dimensions.'.$target['dimension']);
    }

    private function bridge(): FileBridge
    {
        return app(FileBridge::class);
    }

    /**
     * Tenant shortcut mirroring ServerFormPage::getRecord()'s idiom.
     */
    public function server(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }

    /* ------------------------------------------------------------------ */
    /*  View-facing accessors                                              */
    /* ------------------------------------------------------------------ */

    /** Absolute URL of the non-Livewire tile endpoint (js appends ?world&dim&rx&rz&v&epoch). */
    public function tileBaseUrl(): string
    {
        return route('mc-toolkit.map.tile', ['uuid_short' => $this->server()->uuid_short]);
    }

    public function canManageMarkers(): bool
    {
        return $this->canMutateMarkers($this->server());
    }

    /** Spawn anchor handed to the JS initial-fit logic (falls back to origin). */
    public function spawnAnchor(): array
    {
        $target = $this->targetByKey($this->selectedWorld);

        return [
            'x' => is_int($target['spawn_x'] ?? null) ? $target['spawn_x'] : 0,
            'z' => is_int($target['spawn_z'] ?? null) ? $target['spawn_z'] : 0,
        ];
    }
}
