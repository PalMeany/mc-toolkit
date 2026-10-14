<?php

declare(strict_types=1);

namespace McToolkit\Services\Content;

use App\Models\Server;
use Illuminate\Support\Carbon;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\Registries\CurseForgeService;
use McToolkit\Services\Catalog\Registries\HangarService;
use McToolkit\Services\Catalog\Registries\ModrinthService;
use McToolkit\Support\CacheKeys;
use McToolkit\Support\HttpJsonCache;
use Throwable;

/**
 * Lightweight "is there a newer release than what's installed" scanner for the
 * content trio (Plugins / Mods / Modpacks).
 *
 * Owner: wave-B content agent. NOT part of the frozen A3 contract — pages consume
 * this as their own first-party helper.
 *
 * Mechanics:
 *  - DISTINCT tracked rows collapse into project-level probes keyed by
 *    {@see self::keyFor()} (`<registry>|<project_id>`, project ids keep their case —
 *    Modrinth ids and Hangar slugs are case-sensitive); modpack child rows therefore
 *    share ONE probe with their umbrella row instead of tripling upstream calls.
 *  - Each probe resolves the newest upstream release TIMESTAMP per adapter:
 *
 *      registry    | source of truth                                   | guard
 *      ------------|---------------------------------------------------|------------------
 *      modrinth    | GET /v2/project/{id}/version → max(date_published)| existing adapter
 *      spigotmc    | GET /v2/resources/{id} → updateDate unix          | existing adapter
 *      hangar      | HangarService::versions() → max(createdAt)        | public API
 *      curseforge  | GET /v1/mods/{id}/files → max(fileDate)           | needs x-api-key
 *
 *  - Foreign rows are skipped gracefully BY CONSTRUCTION (only tracked_by_toolkit
 *    rows feed the probe list); unknown registries just never get probed.
 *  - Availability itself is NOT decided here — the cache must stay independent of
 *    any single row's install time, so scan() returns per-project timestamps and the
 *    page concern compares `latest_ts > row.updated_at` to light the indicator dot
 *    (the installer bumps updated_at on every (re)install, so acting on a dot clears it).
 *  - Whole-server results cache under mctoolkit.updates.<uuid_short>; {@see self::forget()}
 *    drops it whenever content changes or the user hits Refresh.
 */
final class UpdateScanner
{
    /** Cache TTL — aligns with the detection window so dots and banner refresh together. */
    private const CACHE_TTL_SECONDS = 900;

    /** Timestamp keys inside Hangar version payloads (createdAt is the live one). */
    private const HANGAR_TS_KEYS = ['createdAt', 'lastUpdated'];

    private const PROBEABLE_REGISTRIES = ['modrinth', 'spigotmc', 'hangar', 'curseforge'];

    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly HangarService $hangar,
        private readonly CurseForgeService $curseForge,
    ) {}

    /**
     * Canonical scan-map key for one registry project. Registry tokens are lowercased
     * ('spigot' aliases 'spigotmc'); project ids are only trimmed, never case-folded.
     */
    public static function keyFor(?string $registry, ?string $projectId): string
    {
        $registry = strtolower(trim((string) $registry));

        if ($registry === 'spigot') {
            $registry = 'spigotmc';
        }

        return $registry.'|'.trim((string) $projectId);
    }

    /**
     * Newest-release snapshot for every probeable project tracked on $server.
     *
     * @return array<string, array{latest_ts: ?int, latest_version: ?string}>
     *               keys are {@see self::keyFor()}
     */
    public function scan(Server $server): array
    {
        $key = CacheKeys::ns('updates', $server);

        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $map = [];

        foreach ($this->collectProbes($server) as [$registry, $projectId]) {
            try {
                $snapshot = $this->probe($registry, $projectId);
            } catch (Throwable) {
                continue; // one flaky upstream never blinds every other row
            }

            if ($snapshot !== null) {
                $map[self::keyFor($registry, $projectId)] = $snapshot;
            }
        }

        cache()->put($key, $map, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $map;
    }

    /** Drop the cached snapshot so the next render re-probes (installs, updates, Refresh). */
    public function forget(Server $server): void
    {
        cache()->forget(CacheKeys::ns('updates', $server));
    }

    /**
     * Distinct (registry, project_id) pairs across ALL toolkit-tracked rows of the server.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function collectProbes(Server $server): array
    {
        try {
            /** @var \Illuminate\Database\Eloquent\Collection<int, InstalledContent> $rows */
            $rows = InstalledContent::query()
                ->where('server_id', $server->getKey())
                ->where('tracked_by_toolkit', true)
                ->get();
        } catch (Throwable) {
            return [];
        }

        $seen = [];
        $probes = [];

        foreach ($rows as $row) {
            $pairKey = self::keyFor($row->registry, $row->project_id);
            [$registry, $projectId] = explode('|', $pairKey, 2);

            if ($projectId === '' || !in_array($registry, self::PROBEABLE_REGISTRIES, true)) {
                continue; // foreign rows + unprobeable registries skip gracefully
            }

            if (isset($seen[$pairKey])) {
                continue;
            }

            $seen[$pairKey] = true;

            $probes[] = [$registry, $projectId];
        }

        return $probes;
    }

    /**
     * One adapter lookup translated into a normalized snapshot (null ⇒ no usable data).
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probe(string $registry, string $projectId): ?array
    {
        return match ($registry) {
            'modrinth' => $this->probeModrinth($projectId),
            'spigotmc' => $this->probeSpiget($projectId),
            'hangar' => $this->probeHangar($projectId),
            'curseforge' => $this->probeCurseForge($projectId),
            default => null,
        };
    }

    /**
     * Modrinth versions arrive newest-first, but rely on explicit max() so ordering
     * assumptions can never produce a false negative.
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeModrinth(string $projectId): ?array
    {
        $bestTs = null;
        $bestVersion = null;

        foreach ($this->modrinth->versions($projectId) as $row) {
            $ts = is_string($row['date_published'] ?? null) ? self::isoToTs($row['date_published']) : null;

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($row['version_number'] ?? null) ? $row['version_number'] : null;
            }
        }

        return $bestTs === null ? null : ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
    }

    /**
     * Spiget resource detail carries updateDate as a unix int (or float on some payloads).
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeSpiget(string $resourceId): ?array
    {
        $payload = HttpJsonCache::get(
            'https://api.spiget.org/v2/resources/'.rawurlencode($resourceId),
            120,
            ['fields' => implode(',', ['name', 'updateDate'])],
        );

        if (!is_array($payload)) {
            return null;
        }

        $updateDate = $payload['updateDate'] ?? null;
        $ts = match (true) {
            is_int($updateDate), is_float($updateDate) => (int) $updateDate,
            is_numeric($updateDate) => (int) $updateDate,
            is_string($updateDate) && $updateDate !== '' => self::isoToTs($updateDate),
            default => null,
        };

        return $ts === null ? null : ['latest_ts' => $ts, 'latest_version' => null];
    }

    /**
     * Hangar versions carry an ISO `createdAt`; the newest published one wins.
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeHangar(string $slug): ?array
    {
        $bestTs = null;
        $bestVersion = null;

        foreach ($this->hangar->versions($slug) as $row) {
            $ts = null;

            foreach (self::HANGAR_TS_KEYS as $keyName) {
                $candidate = $row[$keyName] ?? null;

                if (is_string($candidate) && $candidate !== '') {
                    $ts = self::isoToTs($candidate);

                    if ($ts !== null) {
                        break;
                    }
                }
            }

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($row['name'] ?? null) ? $row['name'] : null;
            }
        }

        return $bestTs === null ? null : ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
    }

    /**
     * CurseForge file listing — the newest upload of any flavour.
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeCurseForge(string $modId): ?array
    {
        if ($this->curseForge->apiKey() === '') {
            return null;
        }

        $bestTs = null;
        $bestVersion = null;

        foreach ($this->curseForge->files($modId, ['pageSize' => 10]) as $file) {
            $ts = is_string($file['fileDate'] ?? null) ? self::isoToTs($file['fileDate']) : null;

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($file['displayName'] ?? null) ? $file['displayName'] : null;
            }
        }

        return $bestTs === null ? null : ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
    }

    /** ISO-8601-ish strings → unix seconds; null when unusable. */
    private static function isoToTs(string $value): ?int
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        $ts = strtotime($trimmed);

        if ($ts !== false) {
            return $ts;
        }

        // Some registries answer microsecond-heavy stamps that strtotime rejects outright.
        $normalized = preg_replace('/(\.\d+)\s*(Z|[+-]\d{2}:?\d{2})?$/i', '', $trimmed) ?? $trimmed;

        if (!Carbon::hasFormat($normalized, Carbon::ATOM)) {
            return null;
        }

        $parsed = Carbon::createFromFormat(Carbon::ATOM, $normalized);

        return $parsed instanceof Carbon ? $parsed->getTimestamp() : null;
    }
}
