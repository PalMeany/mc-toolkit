<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * MCJars (https://mcjars.app) catalog — the versions source for the Versions page.
 *
 * Owner: A3. Public API shape is frozen by the build contract:
 *
 *   types()                                  → grouped type metadata
 *   versions(type, page, perPage, search, snapshotsIn) → paginated version rows
 *   latestBuild(type, version)               → newest build incl. installation steps
 *   buildByName(type, version, name)         → one exact build (additive: loader versions)
 *   javaFor(type, version)                   → Java major of one version row (additive)
 *   buildUrlOf(step)                         → convenience url extraction
 *
 * Endpoint facts verified live on 2026-10-14 (see .research/mcjars-api.md):
 *  (origin: config `minecraft-toolkit.mcjars_base`, default https://mcjars.app)
 *  - GET  /api/v2/types                                         -> {"success":bool,"types":{bucket:{TYPE:{...}}}}
 *  - GET  /api/v3/builds/types/{TYPE}/versions?...&fields=...   -> {"versions":{"total","per_page","page","data":[...]}}
 *    (`fields=all` returns empty objects upstream — explicit field names are REQUIRED)
 *  - GET  /api/v3/builds/types/{TYPE}/versions/{VERSION}/latest -> {"build":{...}|null}
 *    `build.installation` is an ordered list of parallel step GROUPS:
 *    download(url,file,size) | remove(location) | unzip(file,location) — replayable with wings calls.
 */
final class McJarsCatalogService
{
    /** Ordered buckets as shown by the demo UI tabs (missing upstream buckets are skipped). */
    public const BUCKETS = ['recommended', 'established', 'experimental', 'miscellaneous', 'limbo'];

    private const DEFAULT_BASE = 'https://mcjars.app';

    public function __construct(
        private readonly int $cacheSeconds = 21600, // 6h for the (slow-changing) types tree
        private readonly int $listCacheSeconds = 900, // 15m for version pages
    ) {}

    /**
     * Canonical type id (e.g. "PAPER") or null when the value cannot be an MCJars type.
     * Type ids are interpolated into URL paths, so anything outside [A-Z0-9_] is refused.
     */
    public static function normalizeType(string $type): ?string
    {
        $type = Str::upper(trim($type));

        return preg_match('/^[A-Z0-9_]{1,40}$/', $type) === 1 ? $type : null;
    }

    /**
     * Whether the catalog currently lists this type id in any bucket.
     */
    public function hasType(string $type): bool
    {
        $type = self::normalizeType($type);

        if ($type === null) {
            return false;
        }

        foreach ($this->types() as $types) {
            if (isset($types[$type])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Grouped software types keyed by category bucket then uppercased type id.
     * Every entry keeps its upstream metadata and gains the `id` + `bucket` keys.
     *
     * Example: ['recommended' => ['VANILLA' => ['id' => 'VANILLA', 'bucket' => 'recommended', ...]]]
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public function types(): array
    {
        $payload = HttpJsonCache::get(
            $this->base().'/api/v2/types',
            (int) config('minecraft-toolkit.cache_ttl.types', $this->cacheSeconds),
        );

        /** @var array<string, mixed> $raw */
        $raw = is_array($payload) && is_array($payload['types'] ?? null) ? $payload['types'] : [];

        $grouped = [];

        // Deterministic bucket order first, then any leftover buckets upstream added.
        foreach ([...self::BUCKETS, ...array_diff(array_keys($raw), self::BUCKETS)] as $bucket) {
            if (!isset($raw[$bucket]) || !is_array($raw[$bucket])) {
                continue;
            }

            foreach ($raw[$bucket] as $typeId => $meta) {
                $id = self::normalizeType((string) $typeId);

                if (!is_array($meta) || $id === null) {
                    continue;
                }

                $entry = [
                    'id' => $id,
                    'bucket' => (string) $bucket,
                ] + $meta;

                $grouped[(string) $bucket][$id] = $entry;
            }
        }

        return $grouped;
    }

    /**
     * Paginated versions of one type.
     *
     * @param  string  $type  e.g. 'VANILLA', 'PAPER', 'FABRIC'
     * @param  string  $search  substring filter on the version id
     * @param  bool  $snapshotsIn  false hides SNAPSHOT/BETA rows from the result set
     * @return array{total:int, page:int, per_page:int, has_more:bool, data:list<array{id:string, type:string, java:?int, builds:?int, created:?string}>}
     *         `total` / `has_more` describe the UPSTREAM (unfiltered) pagination — the
     *         snapshot filter runs on each page afterwards, so a page may hold fewer rows.
     */
    public function versions(string $type, int $page = 1, int $perPage = 50, string $search = '', bool $snapshotsIn = true): array
    {
        $type = self::normalizeType($type);
        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));

        if ($type === null) {
            return ['total' => 0, 'page' => $page, 'per_page' => $perPage, 'has_more' => false, 'data' => []];
        }

        $query = [
            'page' => $page,
            'per_page' => $perPage,
            'fields' => ['type', 'supported', 'created', 'java', 'builds', 'project_version_id', 'version_id'],
        ];

        if ($search !== '') {
            $query['search'] = $search;
        }

        $payload = HttpJsonCache::get(
            $this->base().'/api/v3/builds/types/'.rawurlencode($type).'/versions',
            $this->listCacheSeconds,
            $query,
        );

        /** @var array<int|string, mixed> $wrapper */
        $wrapper = is_array($payload) && is_array($payload['versions'] ?? null) ? $payload['versions'] : [];
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [];

        $data = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $versionType = is_string($row['type'] ?? null) ? strtoupper(trim($row['type'])) : '';

            if (!$snapshotsIn && in_array($versionType, ['SNAPSHOT', 'BETA'], true)) {
                continue;
            }

            $id = $row['id'] ?? $row['version_id'] ?? '';
            $id = is_string($id) || is_int($id) ? trim((string) $id) : '';

            if ($id === '') {
                continue;
            }

            $data[] = [
                'id' => $id,
                'type' => $versionType,
                'java' => isset($row['java']) ? (is_numeric($row['java']) ? (int) $row['java'] : null) : null,
                'builds' => isset($row['builds']) ? (is_numeric($row['builds']) ? (int) $row['builds'] : null) : null,
                'created' => isset($row['created']) && is_string($row['created']) ? $row['created'] : null,
            ];
        }

        $total = is_numeric($wrapper['total'] ?? null) ? (int) $wrapper['total'] : null;

        return [
            'total' => $total ?? count($data),
            'page' => $page,
            'per_page' => $perPage,
            // Without an upstream total, a full raw page is the only hint that more exist.
            'has_more' => $total !== null ? ($page * $perPage) < $total : count($rows) >= $perPage,
            'data' => $data,
        ];
    }

    /**
     * Latest build for a type/version pair; its `installation` step groups can be
     * replayed against wings (download -> remove/unzip via DaemonFileRepository).
     *
     * @return array{build: array{name:string, installation:list<list<array{type:string, url?:string, file?:string, location?:string, size?:int}>>, changes:list<string>, project_version_id:?string, experimental:bool}}|null
     */
    public function latestBuild(string $type, string $version): ?array
    {
        $type = self::normalizeType($type);
        $version = trim($version);

        if ($type === null || $version === '') {
            return null;
        }

        $payload = HttpJsonCache::get(
            $this->base().'/api/v3/builds/types/'.rawurlencode($type).'/versions/'.rawurlencode($version).'/latest',
            120,
        );

        if (!is_array($payload) || !is_array($payload['build'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $build */
        $build = $payload['build'];

        return ['build' => $this->normalizeBuild($build)];
    }

    /**
     * The build of a type/version pair whose `name` is exactly `$name` — for loaders
     * that is the loader version a modpack asks for (Fabric "0.16.10", Forge "47.2.0",
     * NeoForge "21.1.77", Quilt "0.26.4-beta.1"). Null when MCJars lists no such build.
     *
     * Endpoint verified live on 2026-10-14:
     *   GET /api/v3/builds/types/{TYPE}/versions/{VERSION}?search={name}&per_page=…
     *   -> {"builds":{"total","per_page","page","data":[<same shape as /latest>]}}
     * `search` matches substrings ("0.16.1" also finds "0.16.10"), hence the exact
     * comparison over the returned page.
     *
     * @return array{build: array{name:string, installation:list<list<array{type:string, url?:string, file?:string, location?:string, size?:int}>>, changes:list<string>, project_version_id:?string, experimental:bool}}|null
     */
    public function buildByName(string $type, string $version, string $name): ?array
    {
        $type = self::normalizeType($type);
        $version = trim($version);
        $name = trim($name);

        if ($type === null || $version === '' || $name === '') {
            return null;
        }

        $payload = HttpJsonCache::get(
            $this->base().'/api/v3/builds/types/'.rawurlencode($type).'/versions/'.rawurlencode($version),
            $this->listCacheSeconds,
            ['search' => $name, 'per_page' => 50],
        );

        $wrapper = is_array($payload) && is_array($payload['builds'] ?? null) ? $payload['builds'] : [];

        foreach (is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [] as $build) {
            if (is_array($build) && is_string($build['name'] ?? null) && trim($build['name']) === $name) {
                return ['build' => $this->normalizeBuild($build)];
            }
        }

        return null;
    }

    /**
     * Java major MCJars lists for one version row of a type (the `java` field of the
     * versions listing), null when the row is missing or carries none.
     */
    public function javaFor(string $type, string $version): ?int
    {
        $version = trim($version);

        if ($version === '') {
            return null;
        }

        foreach ($this->versions($type, 1, 50, $version, true)['data'] as $row) {
            if ($row['id'] === $version) {
                return $row['java'] !== null && $row['java'] > 0 ? $row['java'] : null;
            }
        }

        return null;
    }

    /**
     * Convenience for a single download step inside a build's installation groups.
     *
     * @param  array<string, mixed>  $buildInstallationStep
     */
    public function buildUrlOf(array $buildInstallationStep): ?string
    {
        if (($buildInstallationStep['type'] ?? null) !== 'download') {
            return null;
        }

        $url = $buildInstallationStep['url'] ?? null;

        return is_string($url) && $url !== '' ? $url : null;
    }

    /** API origin (config `minecraft-toolkit.mcjars_base`), without trailing slash. */
    private function base(): string
    {
        $base = rtrim(trim((string) config('minecraft-toolkit.mcjars_base', self::DEFAULT_BASE)), '/');

        return str_starts_with($base, 'https://') || str_starts_with($base, 'http://') ? $base : self::DEFAULT_BASE;
    }

    /**
     * Defensive shape-forcing so callers never face loose scalars where arrays are expected.
     *
     * @param  array<string, mixed>  $build
     * @return array{name:string, installation:list<list<array{type:string, url?:string, file?:string, location?:string, size?:int}>>, changes:list<string>, project_version_id:?string, experimental:bool}
     */
    private function normalizeBuild(array $build): array
    {
        $installation = [];
        foreach (is_array($build['installation'] ?? null) ? $build['installation'] : [] as $group) {
            if (!is_array($group)) {
                continue;
            }

            $steps = [];
            foreach ($group as $step) {
                if (!is_array($step)) {
                    continue;
                }

                $steps[] = array_filter([
                    'type' => is_string($step['type'] ?? null) ? $step['type'] : '',
                    'url' => is_string($step['url'] ?? null) ? $step['url'] : null,
                    'file' => is_string($step['file'] ?? null) ? $step['file'] : null,
                    'location' => is_string($step['location'] ?? null) ? $step['location'] : null,
                    'size' => is_int($step['size'] ?? null) ? $step['size'] : null,
                ], fn ($v) => $v !== null);
            }

            $installation[] = $steps;
        }

        return [
            'name' => is_string($build['name'] ?? null) ? $build['name'] : '',
            'installation' => $installation,
            'changes' => array_values(array_filter(
                is_array($build['changes'] ?? null) ? $build['changes'] : [],
                fn ($c) => is_string($c),
            )),
            'project_version_id' => is_string($build['project_version_id'] ?? null) ? $build['project_version_id'] : null,
            'experimental' => (bool) ($build['experimental'] ?? false),
        ];
    }
}
