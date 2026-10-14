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
 *  - DISTINCT tracked rows collapse into project-level probes keyed
 *    `<registry>|<project_id>`; modpack child rows therefore share ONE probe with
 *    their umbrella row instead of tripling upstream calls.
 *  - Each probe resolves the newest upstream release TIMESTAMP per adapter:
 *
 *      registry    | source of truth                                   | guard
 *      ------------|---------------------------------------------------|------------------
 *      modrinth    | GET /v2/project/{id}/version → max(date_published)| existing adapter
 *      spigotmc    | SpigetService::resource() → updateDate unix       | existing adapter
 *      hangar      | HangarService::versions() → best-effort date key  | needs Bearer key
 *      curseforge  | GET /v1/mods/{id}/files → max(fileDate)           | needs x-api-key
 *
 *  - Foreign rows are skipped gracefully BY CONSTRUCTION (only tracked_by_toolkit
 *    rows feed the probe list); unknown registries just never get probed.
 *  - Availability itself is NOT decided here — the cache must stay independent of
 *    any single row's install time, so scan() returns per-project timestamps and the
 *    page concern compares `latest_ts > row.updated_at` to light the indicator dot.
 *  - Whole-server results cache under mctoolkit.updates.<uuid_short> for the page
 *    session TTL below, keeping repeated renders cheap and dot state stable.
 */
final class UpdateScanner
{
    /** Cache TTL — aligns with the detection window so dots and banner refresh together. */
    private const CACHE_TTL_SECONDS = 900;

    /** Best-effort ISO/datetime keys observed inside Hangar version payloads. */
    private const HANGAR_TS_KEYS = ['createDateTime', 'dateCreated', 'created_at', 'lastUpdated', 'created'];

    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly HangarService $hangar,
        private readonly CurseForgeService $curseForge,
    ) {}

    /**
     * Newest-release snapshot for every probeable project tracked on $server.
     *
     * @return array<string, array{latest_ts: ?int, latest_version: ?string}>
     *               keys are "<registry-lowercase>|<project_id>"
     */
    public function scan(Server $server): array
    {
        $key = CacheKeys::ns('updates', $server);

        $cached = cache()->get($key);

        if (is_array($cached)) {
            return $cached;
        }

        $probes = $this->collectProbes($server);
        $map = [];

        foreach ($probes as [$registry, $projectId]) {
            try {
                $snapshot = $this->probe($registry, $projectId);
            } catch (Throwable) {
                continue; // one flaky upstream never blinds every other row
            }

            if ($snapshot !== null) {
                $map[strtolower($registry).'|'.$projectId] = $snapshot;
            }
        }

        cache()->put($key, $map, now()->addSeconds(self::CACHE_TTL_SECONDS));

        return $map;
    }

    /** Force-refreshed variant used when the user clicks Update (dot should re-check soon). */
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
            $registry = strtolower(trim((string) ($row->registry ?? '')));
            $projectId = trim((string) ($row->project_id ?? ''));

            if ($registry === '' || $projectId === '' || ! in_array($registry, self::PROBEABLE_REGISTRIES, true)) {
                continue; // foreign rows + unprobeable registries skip gracefully
            }

            $pairKey = $registry.'|'.$projectId;

            if (isset($seen[$pairKey])) {
                continue;
            }

            $seen[$pairKey] = true;

            $probes[] = [$registry, $projectId];
        }

        return $probes;
    }

    private const PROBEABLE_REGISTRIES = ['modrinth', 'spigot', 'spigotmc', 'hangar', 'curseforge'];

    /**
     * One adapter lookup translated into a normalized snapshot (null ⇒ no usable data).
     *
     * @param  string  $registry  lowercase registry token ('spigot'/'spigotmc' alias both)
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probe(string $registry, string $projectId): ?array
    {
        $snapshot = match ($registry) {
            'modrinth' => $this->probeModrinth($projectId),
            'spigot', 'spigotmc' => $this->probeSpiget($projectId),
            'hangar' => $this->probeHangar($projectId),
            'curseforge' => $this->probeCurseForge($projectId),
            default => null,
        };

        return $snapshot;
    }

    /**
     * Modrinth versions arrive newest-first, but rely on explicit max() so ordering
     * assumptions can never produce a false negative.
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeModrinth(string $projectId): ?array
    {
        $rows = $this->modrinth->versions($projectId);

        if ($rows === []) {
            return null;
        }

        $bestTs = null;
        $bestVersion = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $published = is_string($row['date_published'] ?? null) ? strtotime($row['date_published']) : false;
            $ts = is_int($published) ? $published : null;

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($row['version_number'] ?? null) ? $row['version_number'] : null;
            }
        }

        if ($bestTs === null && $bestVersion === null) {
            return null;
        }

        return ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
    }

    /**
     * Spiget resource detail carries updateDate as a unix int (or float on some payloads).
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeSpiget(string $resourceId): ?array
    {
        // Direct resource read through the shared cached JSON channel keeps parity with
        // SpigetService::resource() while returning EXACTLY what we need without extra fields.
        $payload = HttpJsonCache::get(
            'https://api.spiget.org/v2/resources/'.rawurlencode($resourceId),
            120,
            ['fields' => implode(',', ['name', 'updateDate'])],
        );

        if (! is_array($payload)) {
            return null;
        }

        $updateDate = $payload['updateDate'] ?? null;
        $ts = match (true) {
            is_int($updateDate), is_float($updateDate) => (int) $updateDate,
            is_numeric($updateDate) => (int) $updateDate,
            is_string($updateDate) && $updateDate !== '' => self::isoToTs($updateDate),
            default => null,
        };

        $title = is_string($payload['name'] ?? null) ? $payload['name'] : null;

        return $ts === null ? null : ['latest_ts' => $ts, 'latest_version' => $title];
    }

    /**
     * Hangar version listings are keyed by platform with nested payload objects;
     * timestamp extraction stays defensive because upstream field names drift
     * between API versions (documented unknown in handoff notes).
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeHangar(string $ownerSlug): ?array
    {
        if ($this->hangar->apiKey() === '') {
            return null;
        }

        $rows = $this->hangar->versions($ownerSlug);

        if ($rows === []) {
            return null;
        }

        $bestTs = null;
        $bestVersion = null;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $ts = null;

            foreach (self::HANGAR_TS_KEYS as $keyName) {
                $candidate = $row[$keyName] ?? null;

                if (is_int($candidate) || is_float($candidate)) {
                    $ts = (int) $candidate;

                    break;
                }

                if (is_string($candidate) && $candidate !== '') {
                    $parsed = self::isoToTs($candidate);

                    if ($parsed !== null) {
                        $ts = $parsed;

                        break;
                    }
                }
            }

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($row['name'] ?? null) ? $row['name'] : null;
            }
        }

        if ($bestTs === null && $bestVersion === null) {
            return null;
        }

        return ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
    }

    /**
     * CurseForge file listing — no loader filter needed to find the NEWEST upload,
     * deliberately sidestepping the installer's loader mapping entirely.
     *
     * @return array{latest_ts: ?int, latest_version: ?string}|null
     */
    private function probeCurseForge(string $modId): ?array
    {
        if ($this->curseForge->apiKey() === '') {
            return null;
        }

        $payload = HttpJsonCache::get(
            'https://api.curseforge.com/v1/mods/'.rawurlencode($modId).'/files',
            120,
            ['pageSize' => 10],
            ['x-api-key' => $this->curseForge->apiKey()],
        );

        if (! is_array($payload) || ! is_array($payload['data'] ?? null)) {
            return null;
        }

        $bestTs = null;
        $bestVersion = null;

        foreach ($payload['data'] as $file) {
            if (! is_array($file)) {
                continue;
            }

            $ts = self::isoToTs(is_string($file['fileDate'] ?? null) ? $file['fileDate'] : '');

            if ($ts !== null && ($bestTs === null || $ts > $bestTs)) {
                $bestTs = $ts;
                $bestVersion = is_string($file['displayName'] ?? null) ? $file['displayName'] : null;
            }
        }

        if ($bestTs === null && $bestVersion === null) {
            return null;
        }

        return ['latest_ts' => $bestTs, 'latest_version' => $bestVersion];
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

        $fallback = Carbon::hasFormat($normalized, Carbon::ATOM) ? Carbon::createFromFormat(Carbon::ATOM, $normalized) : null;
        $ts = $fallback?->getTimestamp();

        return $ts ?? null;
    }
}
