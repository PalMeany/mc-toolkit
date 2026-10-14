<?php

declare(strict_types=1);

namespace McToolkit\Services\Servers;

use App\Models\Server;
use Illuminate\Support\Arr;
use McToolkit\Services\FileBridge;
use McToolkit\Support\CacheKeys;

/**
 * Performance sampling pipeline behind the PerformancePage KPI tiles + charts.
 *
 * Two truth sources, strictly layered:
 *
 * 1. WINGS getDetails utilisation (the panel's own `Server::retrieveResources()` channel,
 *    verified against app/Repositories/Daemon/DaemonServerRepository.php:getDetails() +
 *    app/Models/Server.php:retrieveResources()): `state`, `utilization.cpu_absolute`,
 *    `utilization.memory_bytes`, `utilization.disk_bytes`, `utilization.uptime`.
 *    CPU/memory/uptime stay LIVE from this layer even when the bridge is absent.
 *    Wings reports uptime in MILLISECONDS — mirrored here from the core
 *    `Server::formatResource(Uptime)` idiom (`now()->subMillis($amount)`, Server.php:564).
 *
 * 2. OPTIONAL companion bridge file `<bridge-path>/performance.json`
 *    (config('minecraft-toolkit.bridge.path'), default ".toolkit") written by a server-side
 *    companion plugin. Game-side metrics that wings cannot know live here:
 *    tps/mspt + averages, per-world players/entity/chunk counts and top entity types.
 *
 * Expected bridge payload (tolerant rename variants accepted, see the per-field tables in
 * {@see self::num()} callers):
 *
 *     {
 *       "tps": 19.8,
 *       "mspt": 27.4,
 *       "tps_avg": {"one": 19.7, "five": 19.9, "fifteen": 19.9},
 *       "cpu_percent": 33.0,
 *       "memory_bytes": 2029846118,
 *       "uptime_ms": 172800000,
 *       "worlds": [
 *         {"name": "world",         "players": 3, "chunks": 961, "entities": {"Item": 612, "Zombie": 188}},
 *         {"name": "world_nether",  "players": 0, "chunks": 210, "entities": {"Zombified Piglin": 150}}
 *       ]
 *     }
 *
 * Per-world `entities` accepts EITHER an associative count map ({"Zombie": 188}) — in which
 * case the element total is derived as its sum — or an integer total under
 * `entity_count`/`entities`. Renames tolerated per world row: name/world/dimension/label;
 * players/player_count/online/players_online; chunks/chunk_count/loaded_chunks.
 *
 * Sampling semantics:
 * - {@see self::snapshot()} caches the merged sample for 30 seconds so repeated polls land
 *   inside the wings-status cache window instead of hammering nodes (guide §R18).
 * - {@see self::history()} keeps a rolling window (`HISTORY_MAX_POINTS`) persisted for an
 *   hour; a new sample is appended only when the previous one aged past
 *   CacheKeys::PERFORMANCE_STALENESS_SECONDS (30 s) so charts survive short polls.
 * - {@see self::capture()} forces a fresh snapshot BYPASSING the cache window and
 *   write-through-appends it to the history immediately (header Refresh path).
 *
 * Everything below `---- additive ----` in the docblocks is consumed by the PerformancePage
 * only; the first eight keys mirror the frozen CONTRACTS.md shape verbatim.
 */
final class PerformanceSampler
{
    /** Snapshot freshness window (seconds). */
    private const SNAPSHOT_TTL_SECONDS = 30;

    /** Rolling-history persistence — one hour, so idle-page resumption keeps context. */
    private const HISTORY_TTL_SECONDS = 3600;

    /** Hard ceiling of retained samples (20s cadence × 300 ≈ 100 minutes of chart depth). */
    private const HISTORY_MAX_POINTS = 300;

    /** Upper sanity clamp for TPS readings (forks/folia vary, generous but bounded). */
    private const TPS_CLAMP_MAX = 100.0;

    /** Upper sanity clamp for MSPT readings in milliseconds. */
    private const MSPT_CLAMP_MAX = 60000.0;

    public function __construct(private readonly FileBridge $bridge) {}

    /**
     * Latest merged sample, served from a short-lived cache unless $force.
     *
     * ---- frozen (CONTRACTS.md) ----
     * @return array{
     *     tps: ?float,
     *     tps_avg: ?array{one: ?float, five: ?float, fifteen: ?float},
     *     mspt: ?float,
     *     cpu_percent: ?float,
     *     memory_bytes: ?float,
     *     source: 'bridge'|'unavailable',
     * } plus the additive keys below:
     *
     * ---- additive (page-consumed) ----
     * @return array{
     *     bridge_present: bool,
     *     state: string,
     *     captured_at: int,
     *     memory_limit_bytes: ?int,
     *     uptime_ms: ?int,
     *     worlds: list<array{name: string, players: ?int, entities: ?int, chunks: ?int, entities_by_type: array<string, int>}>,
     * }
     */
    public function snapshot(Server $server, bool $force = false): array
    {
        $key = CacheKeys::ns('perf.snapshot', $server);

        if (!$force) {
            $cached = cache()->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        $sample = $this->collect($server);

        cache()->put($key, $sample, now()->addSeconds(self::SNAPSHOT_TTL_SECONDS));

        return $sample;
    }

    /**
     * Forced sampling: bypasses the snapshot cache window and writes the fresh sample
     * through into the rolling history immediately. Used by the page's Refresh action.
     */
    public function capture(Server $server): array
    {
        $sample = $this->snapshot($server, force: true);

        try {
            $this->storeSample($server, $sample);
        } catch (\Throwable) {
            // Charts losing one point during a forced refresh is preferable to failing the UI.
        }

        return $sample;
    }

    /**
     * Rolling window of at most $points samples: [{t, tps, mspt, cpu, mem, state}, ...].
     *
     * A sample is appended whenever the newest stored one is older than
     * CacheKeys::PERFORMANCE_STALENESS_SECONDS — polling cadences below that reuse
     * history untouched so short polls cannot starve wings.
     *
     * @param  positive-int  $points
     * @return list<array{t: int, tps: ?float, mspt: ?float, cpu: ?float, mem: ?int, state: string}>
     */
    public function history(Server $server, int $points = 40): array
    {
        $points = max(1, min(self::HISTORY_MAX_POINTS, $points));

        $key = CacheKeys::ns('perf.history', $server);

        $samples = cache()->get($key);
        $samples = is_array($samples) ? array_values($samples) : [];

        $newestAt = $samples !== [] ? intval(Arr::last($samples)['t'] ?? 0) : 0;

        if ($samples === [] || (time() - $newestAt) >= CacheKeys::PERFORMANCE_STALENESS_SECONDS) {
            try {
                $this->storeSample($server, $this->collect($server));
            } catch (\Throwable) {
                // Append failures keep the old window intact rather than nuking charts.
            }

            $stored = cache()->get($key);
            $samples = is_array($stored) ? array_values($stored) : [];
        }

        return array_slice($samples, -$points);
    }

    /* ------------------------------------------------------------------ */
    /*  Collection                                                         */
    /* ------------------------------------------------------------------ */

    private function collect(Server $server): array
    {
        [$state, $utilization] = $this->wingUtilization($server);

        $bridgePayload = $this->bridgePayload($server);
        $bridgePresent = $bridgePayload !== null;

        // WING-level readings win: they describe the real container/node utilisation even
        // when the bridge exists; bridge numbers only fill the gaps wings cannot see.
        $cpuPercent = self::firstNumeric($utilization, ['cpu_absolute', 'cpu_percent', 'cpu'], 0.0, 100_000.0)
            ?? self::firstNumeric($bridgePayload ?? [], ['cpu_percent', 'cpu_usage', 'cpu'], 0.0, 100_000.0);

        $memoryBytes = self::firstNumeric($utilization, ['memory_bytes', 'mem_bytes'], 0.0, 1.0e16)
            ?? self::firstNumeric($bridgePayload ?? [], ['memory_bytes', 'memory_used', 'jvm_memory', 'heap_bytes', 'used_memory'], 0.0, 1.0e16);

        $uptimeMs = self::firstNumeric($utilization, ['uptime_ms', 'uptime_milliseconds', 'uptime'], 0.0, 1.0e14)
            ?? self::firstNumeric($bridgePayload ?? [], ['uptime_ms', 'uptime_milliseconds', 'uptime'], 0.0, 1.0e14);

        $tps = self::firstNumeric($bridgePayload ?? [], ['tps', 'tps_value', 'tps_now', 'current_tps', 'ticks_per_second'], 0.0, self::TPS_CLAMP_MAX);
        $mspt = self::firstNumeric($bridgePayload ?? [], ['mspt', 'mean_mspt', 'avg_mspt', 'mspt_mean', 'mean_tick_time', 'mean_tick_ms'], 0.0, self::MSPT_CLAMP_MAX);
        $avgTriple = $this->averageTriple($bridgePayload ?? []);

        return [
            'tps' => $tps,
            'tps_avg' => $avgTriple,
            'mspt' => $mspt,
            'cpu_percent' => $cpuPercent,
            'memory_bytes' => $memoryBytes,
            'source' => $bridgePresent ? 'bridge' : 'unavailable',

            // ---- additive ------------------------------------------------------
            'bridge_present' => $bridgePresent,
            'state' => $state !== '' ? $state : 'unknown',
            'captured_at' => time(),
            'memory_limit_bytes' => $this->memoryLimitBytes($server),
            'uptime_ms' => $uptimeMs === null ? null : (int) $uptimeMs,
            'worlds' => $this->worldRows($bridgePayload),
        ];
    }

    /**
     * State + utilisation dict off the daemon details endpoint.
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function wingUtilization(Server $server): array
    {
        $daemonClass = '\App\Repositories\Daemon\DaemonServerRepository';

        try {
            if (class_exists($daemonClass)) {
                $repo = app($daemonClass);
                $details = $repo->setServer($server)->getDetails();

                if (is_array($details)) {
                    $state = strval(Arr::get($details, 'state', ''));
                    $utilization = Arr::get($details, 'utilization');

                    return [$state, is_array($utilization) ? $utilization : []];
                }
            }
        } catch (\Throwable) {
            // Node unreachable — fall through to the model-status projection below.
        }

        try {
            if (method_exists($server, 'retrieveStatus')) {
                return [$server->retrieveStatus()->value, []];
            }
        } catch (\Throwable) {
            // Retrieve chain failed completely: report nothing rather than guessing.
        }

        return ['', []];
    }

    /**
     * Companion-bridge JSON payload, or null when absent/unreadable/unparseable.
     */
    private function bridgePayload(Server $server): ?array
    {
        $dir = rtrim(strval(config('minecraft-toolkit.bridge.path', '.toolkit')), '/');

        if ($dir === '') {
            $dir = '.toolkit';
        }

        try {
            $payload = $this->bridge->readJson($server, $dir . '/performance.json');
        } catch (\Throwable) {
            return null;
        }

        return is_array($payload) ? $payload : null;
    }

    /**
     * Panel-configured memory cap in bytes ("MiB multiplied" convention verified against
     * app/Filament/Server/Widgets/ServerOverview.php:51 and ServerResourceType limits).
     */
    private function memoryLimitBytes(Server $server): ?int
    {
        $limit = $server->getAttribute('memory');

        if (!is_numeric($limit) || floatval($limit) <= 0) {
            return null;
        }

        $multiplier = config('panel.use_binary_prefix') ? 1024 * 1024 : 1000 * 1000;

        return (int) round(floatval($limit) * $multiplier);
    }

    /**
     * 1-minute / 5-minute / 15-minute average triple from the bridge payload.
     * Slots that never resolve stay null so the UI renders dashes honestly.
     */
    private function averageTriple(array $bridge): ?array
    {
        $container = self::firstArray($bridge, ['tps_avg', 'averages', 'tps_averages', 'avg_tps']);

        $resolve = function (?array $base, array $slots): ?float {
            return $base === null ? null : self::firstNumeric($base, $slots, 0.0, self::TPS_CLAMP_MAX);
        };

        // Flat fallbacks for payloads that skipped the nesting level.
        $flat = $container === null ? $bridge : [];

        $one = $resolve($container, ['one', 'one_min', 'min_1', '1m', 'minute'])
            ?? self::firstNumeric($flat, ['tps_one', 'tps_1m', 'tps_one_min'], 0.0, self::TPS_CLAMP_MAX);

        $five = $resolve($container, ['five', 'five_min', 'min_5', '5m'])
            ?? self::firstNumeric($flat, ['tps_five', 'tps_5m', 'tps_five_min'], 0.0, self::TPS_CLAMP_MAX);

        $fifteen = $resolve($container, ['fifteen', 'fifteen_min', 'min_15', '15m'])
            ?? self::firstNumeric($flat, ['tps_fifteen', 'tps_15m', 'tps_fifteen_min'], 0.0, self::TPS_CLAMP_MAX);

        if ($one === null && $five === null && $fifteen === null) {
            return null;
        }

        return ['one' => $one, 'five' => $five, 'fifteen' => $fifteen];
    }

    /**
     * Normalized per-world rows from the bridge payload.
     *
     * @return list<array{name: string, players: ?int, entities: ?int, chunks: ?int, entities_by_type: array<string, int>}>
     */
    private function worldRows(?array $bridge): array
    {
        if ($bridge === null) {
            return [];
        }

        $entries = self::firstArray($bridge, ['worlds', 'dimensions', 'per_world']);
        $rows = [];

        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $name = self::firstString($entry, ['name', 'world', 'dimension', 'label']);

            if ($name === '') {
                continue;
            }

            $byType = [];
            $entityTotal = null;

            foreach (['entity_types', 'entities_by_type', 'by_type'] as $mapKey) {
                if (isset($entry[$mapKey]) && is_array($entry[$mapKey])) {
                    foreach ($entry[$mapKey] as $type => $count) {
                        if (is_int($count) || is_float($count)) {
                            $byType[strval($type)] = max(0, intval($count));
                        }
                    }
                }
            }

            $rawEntities = $entry['entities'] ?? null;

            if (is_array($rawEntities)) {
                foreach ($rawEntities as $type => $count) {
                    if (is_int($count) || is_float($count)) {
                        $byType[strval($type)] = max(0, intval($count));
                    }
                }

                $entityTotal = array_sum($byType);
            } elseif (is_numeric($rawEntities)) {
                $entityTotal = max(0, intval($rawEntities));
            } else {
                $explicitTotal = self::firstNumeric($entry, ['entity_count', 'total_entities'], 0, 1.0e12);
                $entityTotal = $explicitTotal === null ? null : intval($explicitTotal);
            }

            arsort($byType);

            $rows[] = [
                'name' => $name,
                'players' => self::nullableInt($entry, ['players', 'player_count', 'online', 'players_online']),
                'entities' => $entityTotal,
                'chunks' => self::nullableInt($entry, ['chunks', 'chunk_count', 'loaded_chunks']),
                'entities_by_type' => $byType,
            ];
        }

        return $rows;
    }

    /* ------------------------------------------------------------------ */
    /*  History storage                                                    */
    /* ------------------------------------------------------------------ */

    /**
     * Compact sample point appended to the rolling window (trimmed, hour TTL).
     */
    private function storeSample(Server $server, array $sample): void
    {
        $key = CacheKeys::ns('perf.history', $server);

        $samples = cache()->get($key);
        $samples = is_array($samples) ? array_values($samples) : [];

        $samples[] = [
            't' => intval($sample['captured_at'] ?? time()),
            'tps' => isset($sample['tps']) && is_numeric($sample['tps']) ? (float) $sample['tps'] : null,
            'mspt' => isset($sample['mspt']) && is_numeric($sample['mspt']) ? (float) $sample['mspt'] : null,
            'cpu' => isset($sample['cpu_percent']) && is_numeric($sample['cpu_percent']) ? (float) $sample['cpu_percent'] : null,
            'mem' => isset($sample['memory_bytes']) && is_numeric($sample['memory_bytes']) ? (int) $sample['memory_bytes'] : null,
            'state' => strval($sample['state'] ?? ''),
        ];

        $samples = array_slice($samples, -self::HISTORY_MAX_POINTS);

        cache()->put($key, $samples, now()->addSeconds(self::HISTORY_TTL_SECONDS));
    }

    /* ------------------------------------------------------------------ */
    /*  Small tolerant readers                                             */
    /* ------------------------------------------------------------------ */

    /**
     * First key present whose value passes numeric + bounds validation.
     *
     * @param  list<string>|array<int, string>  $keys
     */
    private static function firstNumeric(array $data, array $keys, float $min, float $max): ?float
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $data)) {
                continue;
            }

            $raw = $data[$key];

            if (!is_numeric($raw)) {
                continue;
            }

            $value = (float) $raw;

            if (!is_finite($value) || $value < $min || $value > $max) {
                continue;
            }

            return $value;
        }

        return null;
    }

    private static function nullableInt(array $data, array $keys): ?int
    {
        $found = self::firstNumeric($data, $keys, 0.0, 1.0e12);

        return $found === null ? null : intval($found);
    }

    /**
     * @return mixed
     */
    private static function firstString(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * First nested array under any of the listed keys.
     *
     * @return list<mixed>
     */
    private static function firstArray(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;

            if (is_array($value)) {
                return $value;
            }
        }

        return [];
    }
}
