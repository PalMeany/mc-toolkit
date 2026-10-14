<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Facades\Activity;
use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Exception;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Collection;
use Livewire\Attributes\On;
use McToolkit\Models\MapMarker;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Maps\MapTileService;

/**
 * Top-down block map of the current server worlds.
 *
 * Live demo-parity surface (see /.research/demo-findings.md §Map): left control stack
 * (world switcher, layer preset toggles, persisted markers CRUD, player panel),
 * main canvas driven by the inline ES2020 script in resources/views/server/map.blade.php,
 * header Redraw control plus the shared filesystem-detection banner.
 *
 * Backend facts kept server-side here:
 * - which regions exist (MapTileService::availableRegionsWithMeta),
 * - world list + spawn coordinates (McToolkit\Services\Worlds\WorldsService when the
 *   wave-B service is deployed; graceful name-scan fallback otherwise - see worlds()),
 * - companion-bridge player positions/time (.toolkit/map.json; ABSENT bridges simply
 *   hide pins behind an explanatory placeholder chip rather than erroring),
 * - persisted markers (mc_map_markers, gated on manage + map).
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

    /** Client-freshness epoch appended to every tile request URL (Redraw bumps it). */
    public int $tileEpoch = 1;

    /* ------------------------------------------------------------------ */
    /*  Interactive state                                                  */
    /* ------------------------------------------------------------------ */

    /** Currently rendered world directory name (level-name value family). */
    public string $selectedWorld = '';

    /**
     * Compact world descriptors fed to the radio list; keys are exactly what the
     * blade reads: name, default(bool), dimension, title(?).
     *
     * @var list<array{name:string, default:bool, dimension:string, title:?string, spawn_x:?int, spawn_z:?int}>
     */
    public array $worlds = [];

    /**
     * Regions known for the selected world (`rx`,`rz`,`mtime`,`size`) + truncated flag.
     *
     * @var array{regions:list<array{rx:int, rz:int, mtime:?int, size:int}>, truncated:bool}
     */
    public array $regionMeta = ['regions' => [], 'truncated' => false];

    /**
     * Markers of the selected world's dimension: id,label,x,z,color.
     *
     * @var list<array{id:int|string, label:string, x:int, z:int, color:?string}>
     */
    public array $markers = [];

    /** Toggle: follow mode recenters the viewport every ping onto the lead pin. */
    public bool $follow = false;

    /** Layer presets drawn client-side only (Spawn / World border annotation overlays). */
    public bool $showSpawn = true;

    public bool $showWorldBorder = false;

    /**
     * Companion-bridge pins, normalized from `.toolkit/map.json`: name,x,y,z,dimension.
     *
     * @var list<array{name:string, x:int|float|null, y:int|float|null, z:int|float|null, dimension:string}>
     */
    public array $pins = [];

    /** In-game day clock ticks from the bridge; null renders dashes client-side. */
    public ?int $gameTimeTicks = null;

    /** Detection summary snapshot powering the pinned banner above the canvas. */
    public array $software = [];

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                          */
    /* ------------------------------------------------------------------ */

    public function mount(): void
    {
        $this->authorizeAccess();

        $server = $this->server();

        $this->software = app(DetectionService::class)->detect($server);

        $this->hydrateWorlds();

        $this->selectedWorld = $this->resolveDefaultWorld();

        $this->loadRegionMeta();
        $this->loadMarkers();

        // Silent first hydration of bridge pins/time so the canvas paints instantly.
        $this->refreshBridge();
    }

    public static function canAccess(): bool
    {
        $enabledByConfig = (bool) config('minecraft-toolkit.pages.enabled.map', true);

        return $enabledByConfig
            && parent::canAccess()
            && user()?->can('minecraft-toolkit.use', Filament::getTenant());
    }

    protected function authorizeAccess(): void
    {
        abort_unless(user()?->can('minecraft-toolkit.map', Filament::getTenant()), 403);
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
     * (The detection banner carries its own Detect-again control.)
     *
     * Marker creation is a mounted Filament Action modal (IMPLEMENTATION-GUIDE §2.4:
     * schema-backed fields, never raw HTML forms) — triggerable from the markers card.
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
                ->visible(fn () => $this->canManageMarkers())
                ->schema([
                    TextInput::make('newLabel')
                        ->label(trans('minecraft-toolkit::pages.map.layers.markers'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('newX')
                        ->label('X')
                        ->numeric()
                        ->required(),
                    TextInput::make('newZ')
                        ->label('Z')
                        ->numeric()
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
     *   {"players":[{name,x,y,z,dimension}], "time":12345}
     *   {"players":{"Steve":{x,y,z}},      "time":12345}
     *   [{name,x,y,z}, ...]
     */
    public function refreshBridge(): void
    {
        [$allPins, $ticks] = $this->readBridgeState($this->server());

        // Dimension scoping: only pins matching the selected world's bucket surface.
        $dim = $this->currentDimension();

        $this->pins = array_values(array_filter($allPins, static fn (array $p): bool => ($p['dimension'] ?? 'overworld') === $dim));
        $this->gameTimeTicks = $ticks;
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

    public function selectWorld(string $world): void
    {
        $candidate = collect($this->worlds)->firstWhere('name', $world);

        if (! is_array($candidate)) {
            return; // unknown slug - keep current selection untouched.
        }

        $this->selectedWorld = $candidate['name'];

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
     * REDRAW parity control: purges the LOCAL `.mca` mirror for the active world,
     * moves the render generation forward (fresh server-side pixels), and bumps the
     * client cache-bust epoch so the JS scheduler drops its memory tiles too.
     */
    public function redraw(): void
    {
        $server = $this->server();

        $this->purgeLocalMirror($server, $this->selectedWorld);

        try {
            app(MapTileService::class)->bumpGeneration($server, $this->selectedWorld);
        } catch (\Throwable) {
            // generation counters degrade gracefully; TTL expiry remains the safety net
        }

        $this->tileEpoch++;

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.map.redraw'))
            ->body(strip_tags(trans('minecraft-toolkit::pages.map.render_note', ['world' => $this->selectedWorld])))
            ->success()
            ->send();
    }

    /* ------------------------------------------------------------------ */
    /*  Markers CRUD                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * Persists one marker from the mounted `add_marker` Action schema; schema rules
     * validate label/x/z/color before this runs, and the mutation gate stays
     * map domain + manage.
     *
     * @param  array{newLabel?:mixed, newX?:mixed, newZ?:mixed, newColor?:mixed}  $data
     */
    public function createMarker(array $data): void
    {
        $server = $this->server();

        if (! $this->canMutateMarkers($server)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        $label = trim(strval($data['newLabel'] ?? ''));
        $x = intval($data['newX'] ?? 0);
        $z = intval($data['newZ'] ?? 0);

        if ($label === '' || ! is_numeric($data['newX'] ?? null) || ! is_numeric($data['newZ'] ?? null)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.map.layers.markers'))
                ->body(trans('minecraft-toolkit::common.buttons.no_results'))
                ->danger()
                ->send();

            return;
        }

        MapMarker::query()->create([
            'server_id' => $server->id,
            'label' => $label,
            'x' => $x,
            'z' => $z,
            'dimension' => $this->currentDimension(),
            'color' => $this->sanitizedHexColor(strval($data['newColor'] ?? '')),
        ]);

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
     * Removes one marker owned by this server; same mutation gates as creation.
     */
    public function deleteMarker(int $markerId): void
    {
        $server = $this->server();

        if (! $this->canMutateMarkers($server)) {
            Notification::make()
                ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
                ->danger()
                ->send();

            return;
        }

        /** @var MapMarker|null $marker */
        $marker = MapMarker::query()
            ->whereKey($markerId)
            ->where('server_id', $server->id)
            ->where('dimension', $this->currentDimension())
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
                ['label' => $marker->label],
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
        return user()?->can('minecraft-toolkit.map', $server)
            && user()?->can('minecraft-toolkit.manage', $server);
    }

    /* ------------------------------------------------------------------ */
    /*  Data hydration                                                     */
    /* ------------------------------------------------------------------ */

    private function hydrateWorlds(): void
    {
        $server = $this->server();
        $rows = [];

        // Preferred source: the frozen WorldsService contract (wave-B deliverable).
        if (class_exists(\McToolkit\Services\Worlds\WorldsService::class)) {
            try {
                foreach (app(\McToolkit\Services\Worlds\WorldsService::class)->listWorlds($server) as $row) {
                    if (! is_array($row) || ! isset($row['name']) || ! is_string($row['name'])) {
                        continue;
                    }

                    $rows[] = [
                        'name' => $row['name'],
                        'default' => (bool) ($row['default'] ?? false),
                        'dimension' => strval($row['dimension'] ?? 'overworld'),
                        'title' => isset($row['title']) && is_string($row['title']) ? $row['title'] : null,
                        'spawn_x' => is_numeric($row['spawn']['x'] ?? null) ? (int) $row['spawn']['x'] : null,
                        'spawn_z' => is_numeric($row['spawn']['z'] ?? null) ? (int) $row['spawn']['z'] : null,
                    ];
                }
            } catch (\Throwable) {
                $rows = [];
            }
        }

        // Graceful fallback (documented deviation candidate): a light root-directory
        // scan looking for region-bearing folders, bounded to eight existence probes.
        if ($rows === []) {
            $rows = $this->fallbackWorldScan($server);
        }

        $this->worlds = $rows;
    }

    /**
     * Returns `list<array{name,dimension}>` style minimal descriptors for the
     * fallback path; spawn stays unknown there.
     *
     * @return list<array{name:string, default:bool, dimension:string, title:?string, spawn_x:?int, spawn_z:?int}>
     */
    private function fallbackWorldScan(Server $server): array
    {
        $bridge = $this->bridge();
        $rootDirs = [];

        try {
            foreach ($bridge->ls($server, '/') as $entry) {
                if (($entry['directory'] ?? false) === true) {
                    $rootDirs[] = strval($entry['name']);
                }
            }
        } catch (\Throwable) {
            return [];
        }

        // Prioritize conventional names first; probe budget keeps the daemon calm.
        usort($rootDirs, static fn (string $a, string $b): int => str_starts_with($a, 'world') ? -1 : (str_starts_with($b, 'world') ? 1 : 0));
        $probed = 0;
        $found = [];

        foreach (array_slice($rootDirs, 0, 16) as $dir) {
            if ($probed >= 8) {
                break;
            }

            $probed++;

            if ($bridge->exists($server, rtrim($dir, '/').'/region')) {
                $found[] = $dir;
            }
        }

        return array_map(static fn (string $name): array => [
            'name' => $name,
            'default' => false,
            'dimension' => str_contains($name, 'nether') ? 'nether' : (str_contains($name, 'end') || str_ends_with($name, '_end') ? 'end' : 'overworld'),
            'title' => null,
            'spawn_x' => null,
            'spawn_z' => null,
        ], $found);
    }

    private function resolveDefaultWorld(): string
    {
        // Priority 1: explicit default flag from the world listing.
        $explicit = collect($this->worlds)->firstWhere('default', true);

        // Priority 2: the level-name declared inside server.properties.
        $declaredLevelName = $this->declaredLevelName($this->server());

        $fallbackName = filled($declaredLevelName) ? $declaredLevelName : 'world';
        $listedFallback = collect($this->worlds)->firstWhere('name', $fallbackName);

        return strval($explicit['name'] ?? ($listedFallback !== null ? $fallbackName : (strval(collect($this->worlds)->value('name') ?: $fallbackName))));
    }

    /** Reads the vanilla `level-name=` entry straight from server.properties (bounded read). */
    private function declaredLevelName(Server $server): ?string
    {
        try {
            $raw = $this->bridge()->read($server, '/server.properties', 32_768);
        } catch (\Throwable) {
            return null;
        }

        if ($raw === null || preg_match('/^level-name[ \t]*=[ \t]*(.+)$/m', $raw, $m) !== 1) {
            return null;
        }

        $value = trim(strval($m[1]));

        return $value === '' ? null : $value;
    }

    private function loadRegionMeta(): void
    {
        try {
            $meta = app(MapTileService::class)->availableRegionsWithMeta($this->server(), $this->selectedWorld);
        } catch (\Throwable $exception) {
            report($exception);
            $meta = ['regions' => [], 'truncated' => false];
        }

        $this->regionMeta = $meta;
    }

    private function loadMarkers(): void
    {
        $server = $this->server();

        try {
            /** @var Collection<int, MapMarker> $markers */
            $markers = MapMarker::query()
                ->forServer($server)
                ->dimension($this->currentDimension())
                ->orderBy('id')
                ->get(['id', 'label', 'x', 'z', 'color']);

            $this->markers = $markers
                ->map(static fn (MapMarker $marker): array => [
                    'id' => (int) $marker->getKey(),
                    'label' => $marker->label,
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
     * @return array{0:list<array{name:string, x:int|float|null, y:int|float|null, z:int|float|null, dimension:string}>, 1:?int}
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
        } catch (Exception) {
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

            $pins[] = [
                'name' => mb_substr(trim($name), 0, 64),
                'x' => is_numeric($body['x'] ?? null) ? $body['x'] : null,
                'y' => is_numeric($body['y'] ?? null) ? $body['y'] : null,
                'z' => is_numeric($body['z'] ?? null) ? $body['z'] : null,
                'dimension' => in_array(strtolower(strval($body['dimension'] ?? 'overworld')), ['overworld', 'nether', 'end'], true)
                    ? strtolower(strval($body['dimension']))
                    : 'overworld',
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
            if (is_numeric($candidate)) {
                $ticks = (int) round((float) $candidate);
                break;
            }
        }

        return [$pins, $ticks];
    }

    /**
     * Dimension bucket the current world maps onto; drives marker scoping AND which
     * bridge pins surface on the canvas.
     */
    private function currentDimension(): string
    {
        $row = collect($this->worlds)->firstWhere('name', $this->selectedWorld);

        $declared = is_array($row) ? strval($row['dimension'] ?? '') : '';
        $match = in_array($declared, ['overworld', 'nether', 'end'], true) ? $declared : null;

        return $match
            ?? (str_contains($this->selectedWorld, 'nether') ? 'nether'
                : (str_ends_with($this->selectedWorld, '_the_end') || str_ends_with($this->selectedWorld, '_end') ? 'end'
                    : 'overworld'));
    }

    /**
     * Deletes the locally mirrored `.mca` files of one world (same layout the
     * RegionParser writes under storage_path("app/mctoolkit/<short>/mapcache/<world>")).
     */
    private function purgeLocalMirror(Server $server, string $worldDir): void
    {
        $safeShort = preg_replace('/[^A-Za-z0-9_-]/', '', substr((string) $server->uuid_short, 0, 24)) ?: 'unknown';
        $safeWorld = basename(str_replace('\\', '/', trim($worldDir)));
        $directory = implode(DIRECTORY_SEPARATOR, [storage_path('app'), 'mctoolkit', $safeShort, 'mapcache', $safeWorld]);

        if (! is_dir($directory)) {
            return;
        }

        foreach ((array) glob($directory.DIRECTORY_SEPARATOR.'*.mca') as $file) {
            if (is_string($file)) {
                @unlink($file);
            }
        }
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

    /** Absolute URL of the non-Livewire tile endpoint (js appends ?world&rx&rz&epoch). */
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
        $row = collect($this->worlds)->firstWhere('name', $this->selectedWorld);

        return [
            'x' => is_array($row) && is_numeric($row['spawn_x'] ?? null) ? (int) $row['spawn_x'] : 0,
            'z' => is_array($row) && is_numeric($row['spawn_z'] ?? null) ? (int) $row['spawn_z'] : 0,
        ];
    }
}
