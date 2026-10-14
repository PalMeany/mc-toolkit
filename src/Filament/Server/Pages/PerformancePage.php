<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages;

use App\Enums\TablerIcon;
use App\Models\Server;
use BackedEnum;
use UnitEnum;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Services\Servers\PerformanceSampler;
use McToolkit\Support\Activity;
use McToolkit\Support\CacheKeys;
use Throwable;

/**
 * Performance — live TPS / MSPT tiles plus CPU/memory from wings, charts over a rolling
 * history, and a bridge-fed per-world entity/chunk breakdown.
 *
 * Data layers (see {@see \McToolkit\Services\Servers\PerformanceSampler} docblock):
 * - WINGS always answers CPU %, JVM memory and uptime ("CPU MEMORY UPTIME REMAIN LIVE
 *   regardless") even without any companion plugin installed.
 * - TPS/MSPT + per-world counts come only while the companion bridge writes
 *   `.toolkit/performance.json`; everything degrades into dashes/dimmed overlays otherwise.
 *
 * Polling: `<div wire:poll.visible.20s="tick">` in the view — visible-only cadence so
 * backgrounded tabs stop polling (guide §R18 throttle discipline).
 *
 * Authorization: read-only page ⇒ gate 'minecraft-toolkit.use' only; the frozen permission
 * matrix registers no dedicated performance domain key (CONTRACTS.md → permission FINAL list).
 */
final class PerformancePage extends Page
{
    /** Verified case: app/Enums/TablerIcon.php:2981 = 'tabler-gauge'. */
    protected static string|BackedEnum|null $navigationIcon = TablerIcon::Gauge;

    protected static string|UnitEnum|null $navigationGroup = 'Minecraft';

    protected static ?int $navigationSort = 28;

    protected string $view = 'minecraft-toolkit::server.performance';


    /** Namespaced URL: /server/{uuid_short}/mc-toolkit/performance (README "Feature pages"). */

    protected static ?string $slug = 'mc-toolkit/performance';

    /**
     * Livewire-managed sample payloads (all serializable arrays).
     */
    public array $detected = [];

    public array $snapshot = [];

    public array $history = [];

    public array $worlds = [];

    /** Livewire event emitted by the shared detection-banner component's re-detect control. */
    protected $listeners = ['mc-toolkit.redetect' => 'redetect'];

    /* ------------------------------------------------------------------ */
    /*  Lifecycle                                                          */
    /* ------------------------------------------------------------------ */

    public function mount(): void
    {
        $this->reloadData();
    }

    /**
     * Single access gate. Filament runs it on mount AND on every later Livewire request
     * (CanAuthorizeAccess::mount/hydrate hooks), so tick()/refresh() are covered too.
     * Includes the core conflict-state lock (BlockAccessInConflict parity — that trait's
     * own canAccess would be shadowed by this one).
     */
    public static function canAccess(): bool
    {
        if (! (bool) config('minecraft-toolkit.pages.enabled.performance', true)) {
            return false;
        }

        $server = Filament::getTenant();

        if (! $server instanceof Server || $server->isInConflictState()) {
            return false;
        }

        $user = user();

        return parent::canAccess()
            && $user !== null
            && $user->can('minecraft-toolkit.use', $server);
    }

    public function getTitle(): string
    {
        return trans('minecraft-toolkit::pages.performance.title');
    }

    public static function getNavigationLabel(): string
    {
        return trans('minecraft-toolkit::pages.performance.title');
    }

    public function getSubheading(): string
    {
        return trans('minecraft-toolkit::pages.performance.subheading');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(trans('minecraft-toolkit::common.buttons.refresh'))
                ->icon(TablerIcon::Refresh)
                ->action('refresh'),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Interactions                                                       */
    /* ------------------------------------------------------------------ */

    /**
     * wire:poll tick — normal-path re-sample inside the cache windows.
     * Deliberately SILENT on failure: a background poll firing a danger toast every
     * 20 s would be hostile (documented deviation; explicit Refresh reports failures).
     */
    public function tick(): void
    {
        try {
            $this->reloadData();
        } catch (Throwable) {
            // Silent: charts freeze at their last good frame instead of toast-spamming.
        }
    }

    /**
     * Header Refresh — forced capture that bypasses the snapshot window and writes
     * through into the rolling history immediately.
     */
    public function refresh(): void
    {
        try {
            $this->reloadData(force: true);
        } catch (Throwable) {
            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.refresh'))
                ->body(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(trans('minecraft-toolkit::common.buttons.refresh'))
            ->success()
            ->send();
    }

    /**
     * mc-toolkit.redetect listener dispatched by the detection-banner component:
     * forces DetectionService::detect(true), logs activity, confirms via notification,
     * then keeps KPI/wing readings untouched (detection-only concern).
     */
    public function redetect(): void
    {
        $server = $this->tenant();

        try {
            $this->detected = app(DetectionService::class)->detect($server, force: true);

            Activity::event('mc-toolkit:detection.refresh')->log();

            Notification::make()
                ->title(trans('minecraft-toolkit::common.buttons.detect_again'))
                ->success()
                ->send();
        } catch (Throwable) {
            Notification::make()
                ->title(trans('minecraft-toolkit::banner.detection.title_note'))
                ->body(trans('minecraft-toolkit::banner.failures.gateway_down'))
                ->danger()
                ->send();
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Render helpers consumed by resources/views/server/performance      */
    /* ------------------------------------------------------------------ */

    /** Chart series for a metric: 'tps' | 'mspt' | 'cpu' | 'memory'. @return list<float> */
    public function series(string $metric): array
    {
        $field = match ($metric) {
            'tps' => 'tps',
            'mspt' => 'mspt',
            'cpu' => 'cpu',
            'memory' => 'mem',
            default => null,
        };

        if ($field === null) {
            return [];
        }

        $values = [];

        foreach ($this->history as $row) {
            $value = $row[$field] ?? null;

            if (is_numeric($value)) {
                $values[] = (float) $value;
            }
        }

        return $values;
    }

    public function isBridged(): bool
    {
        return (bool) ($this->snapshot['bridge_present'] ?? false);
    }

    /** Container state key of the newest sample (lower-cased wing state string). */
    public function stateKey(): string
    {
        return mb_strtolower(strval($this->snapshot['state'] ?? ''));
    }

    /**
     * Semantic color for the state pill (verified core convention: Running=success,
     * Installing/Starting=primary, Offline=danger — design-system §15.5).
     */
    public function stateColor(): string
    {
        return match ($this->stateKey()) {
            'running', 'online' => 'success',
            'starting', 'installing', 'restarting', 'reinstalling', 'migration' => 'primary',
            'offline', 'missing', 'exited', 'killed' => 'danger',
            default => 'gray',
        };
    }

    /** Human uptime from the newest sample's milliseconds (core subMillis idiom, Server.php:564). */
    public function uptimeText(): ?string
    {
        $ms = $this->snapshot['uptime_ms'] ?? null;

        if (!is_numeric($ms) || intval($ms) <= 0) {
            return null;
        }

        try {
            return now()->subMillis(intval($ms))->diffForHumans(syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE, short: true, parts: 4);
        } catch (Throwable) {
            return null;
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Internals                                                          */
    /* ------------------------------------------------------------------ */

    private function reloadData(bool $force = false): void
    {
        $server = $this->tenant();
        $sampler = app(PerformanceSampler::class);

        // Cached detect (~instant): keeps the banner chips in sync after settings changes.
        $this->detected = app(DetectionService::class)->detect($server);

        $this->snapshot = $force ? $sampler->capture($server) : $sampler->snapshot($server);
        $this->history = $sampler->history($server);
        $this->worlds = $this->worldRows($server);
    }

    /**
     * Merges bridge-provided per-world stats with a world enumeration channel:
     *
     * 1. Bridge rows ({@see PerformanceSampler} shape) carry players/entities/chunks/top types.
     * 2. WorldsService::listWorlds() (wave-B service, guarded at runtime) contributes the
     *    authoritative enumeration + dimension metadata; result lightly cached here (10 min)
     *    since its internal scan hits wings directories.
     * 3. Without the service AND without bridge data, falls back to root-level directory
     *    names starting with "world" via FileBridge::safeLs() (5-min cache); every metric
     *    stays dash/placeholder so nothing invented ever renders (honesty mandate).
     *
     * @return list<array{name: string, badge: ?string, players: ?int, entities: ?int, chunks: ?int, top: list<array{type: string, count: int}>}>
     */
    private function worldRows(Server $server): array
    {
        $rowsByName = [];
        $order = [];

        foreach ($this->snapshot['worlds'] ?? [] as $bridgeRow) {
            if (!is_array($bridgeRow)) {
                continue;
            }

            $name = strval($bridgeRow['name'] ?? '');

            if ($name === '' || isset($rowsByName[$name])) {
                continue;
            }

            $order[] = $name;
            $rowsByName[$name] = [
                'players' => isset($bridgeRow['players']) && is_int($bridgeRow['players']) ? $bridgeRow['players'] : null,
                'entities' => isset($bridgeRow['entities']) && is_int($bridgeRow['entities']) ? $bridgeRow['entities'] : null,
                'chunks' => isset($bridgeRow['chunks']) && is_int($bridgeRow['chunks']) ? $bridgeRow['chunks'] : null,
                'top' => $this->topEntities(is_array($bridgeRow['entities_by_type'] ?? null) ? $bridgeRow['entities_by_type'] : []),
            ];
        }

        $meta = $this->listingMeta($server);

        foreach (array_keys($meta) as $name) {
            if (!isset($rowsByName[$name])) {
                $order[] = $name;
                $rowsByName[$name] = [
                    'players' => null,
                    'entities' => null,
                    'chunks' => null,
                    'top' => [],
                ];
            }
        }

        $rows = [];

        foreach ($order as $name) {
            // Bridge names without a folder row (Paper 26.x reports `world_nether` /
            // `world_the_end` although both live inside `world/`) badge by suffix.
            $info = $meta[$name] ?? $this->suffixMeta($name);

            $rows[] = [
                'name' => $name,
                'badge' => $this->badgeFor($name, $info),
                ...$rowsByName[$name],
            ];
        }

        usort($rows, function (array $a, array $b): int {
            $aDefault = $a['badge'] === 'Default' ? 0 : 1;
            $bDefault = $b['badge'] === 'Default' ? 0 : 1;

            return [$aDefault, $a['name']] <=> [$bDefault, $b['name']];
        });

        return $rows;
    }

    /**
     * Lightly-cached world listing metadata.
     *
     * @return array<string, array{default?: bool, linked?: bool, dimension?: string}>
     */
    private function listingMeta(Server $server): array
    {
        $serviceClass = '\McToolkit\Services\Worlds\WorldsService';

        try {
            if (class_exists($serviceClass) && method_exists($serviceClass, 'listWorlds')) {
                $listing = cache()->remember(
                    CacheKeys::ns('perf.worlds', $server),
                    now()->addMinutes(10),
                    fn (): mixed => app($serviceClass)->listWorlds($server),
                );

                $meta = [];

                foreach ((array) $listing as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }

                    $name = strval($entry['name'] ?? '');

                    if ($name !== '') {
                        $meta[$name] = [
                            'default' => (bool) ($entry['default'] ?? false),
                            'linked' => (bool) ($entry['linked'] ?? false),
                            'dimension' => filled($entry['dimension'] ?? null) ? strval($entry['dimension']) : '',
                        ];
                    }
                }

                return $meta;
            }
        } catch (Throwable) {
            // Service present-but-broken degrades to the cheap root-scan fallback below.
        }

        return $this->fallbackRootWorlds($server);
    }

    /**
     * Names-only fallback when neither bridge nor WorldsService exist yet.
     * Badges derive purely from conventional suffixes; metrics stay null.
     *
     * @return array<string, array{default: bool, linked: bool, dimension: string}>
     */
    private function fallbackRootWorlds(Server $server): array
    {
        try {
            $names = cache()->remember(
                CacheKeys::ns('perf.worldroots', $server),
                now()->addMinutes(5),
                function () use ($server): array {
                    $roots = [];

                    foreach (app(FileBridge::class)->safeLs($server, '/') as $entry) {
                        $name = strval($entry['name'] ?? '');

                        if (($entry['directory'] ?? false) && mb_strtolower($name) !== '' && str_starts_with(mb_strtolower($name), 'world')) {
                            $roots[] = $name;
                        }
                    }

                    return $roots;
                },
            );
        } catch (Throwable) {
            return [];
        }

        $meta = [];

        foreach ((array) $names as $name) {
            $meta[strval($name)] = $this->suffixMeta(strval($name));
        }

        return $meta;
    }

    /**
     * Badge metadata derived from Bukkit-style name suffixes alone (`_nether`, `_the_end`).
     *
     * @return array{default: bool, linked: bool, dimension: string}
     */
    private function suffixMeta(string $name): array
    {
        $lower = mb_strtolower($name);

        return [
            'default' => false,
            'linked' => str_ends_with($lower, '_nether') || str_ends_with($lower, '_the_end'),
            'dimension' => str_ends_with($lower, '_nether') ? 'nether' : (str_ends_with($lower, '_the_end') || str_ends_with($lower, '_end') ? 'end' : ''),
        ];
    }

    private function badgeFor(string $name, array $info): ?string
    {
        if (!empty($info['default'])) {
            return 'Default';
        }

        $dimension = mb_strtolower(strval($info['dimension'] ?? ''));

        if ($dimension !== '') {
            return match ($dimension) {
                'nether' => 'Nether',
                'end' => 'End',
                'overworld' => 'Overworld',
                default => ucfirst($dimension),
            };
        }

        return null;
    }

    /**
     * Top-entity chip list, sorted descending, capped for the UI.
     * Expected bridge shape {'entities': {'zombie': 123}} — normalized by the sampler.
     *
     * @param  array<string, int>  $byType
     * @return list<array{type: string, count: int}>
     */
    private function topEntities(array $byType): array
    {
        arsort($byType);

        $top = [];

        foreach ($byType as $type => $count) {
            $top[] = ['type' => strval($type), 'count' => max(0, intval($count))];
        }

        return array_slice($top, 0, 8);
    }

    private function tenant(): Server
    {
        $tenant = Filament::getTenant();
        \assert($tenant instanceof Server);

        return $tenant;
    }
}
