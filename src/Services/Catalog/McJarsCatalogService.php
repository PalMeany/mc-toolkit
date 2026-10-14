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
 *   buildUrlOf(step)                         → convenience url extraction
 *
 * Endpoint facts verified live on 2026-10-14 (see .research/mcjars-api.md):
 *  - GET  https://mcjars.app/api/v2/types                       -> {"success":bool,"types":{bucket:{TYPE:{...}}}}
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

    private const TYPES_URL = 'https://mcjars.app/api/v2/types';

    public function __construct(
        private readonly int $cacheSeconds = 21600, // 6h for the (slow-changing) types tree
        private readonly int $listCacheSeconds = 900, // 15m for version pages
    ) {}

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
        $payload = HttpJsonCache::get(self::TYPES_URL, $this->cacheSeconds);

        /** @var array<string, mixed> $raw */
        $raw = is_array($payload) ? ($payload['types'] ?? []) : [];

        $grouped = [];

        // Deterministic bucket order first, then any leftover buckets upstream added.
        foreach ([...self::BUCKETS, ...array_diff(array_keys($raw), self::BUCKETS)] as $bucket) {
            if (!isset($raw[$bucket]) || !is_array($raw[$bucket])) {
                continue;
            }

            foreach ($raw[$bucket] as $typeId => $meta) {
                if (!is_array($meta)) {
                    continue;
                }

                $entry = [
                    'id' => Str::upper((string) $typeId),
                    'bucket' => (string) $bucket,
                ] + $meta;

                $grouped[(string) $bucket][(string) $typeId] = $entry;
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
     * @return array{total:int, page:int, data:list<array{id:string, type:string, java:?int, builds:?int, created:?string}>}
     */
    public function versions(string $type, int $page = 1, int $perPage = 50, string $search = '', bool $snapshotsIn = true): array
    {
        $type = Str::upper(trim($type));

        $query = [
            'page' => max(1, $page),
            'per_page' => min(200, max(1, $perPage)),
            'fields' => ['type', 'supported', 'created', 'java', 'builds', 'project_version_id', 'version_id'],
        ];

        if ($search !== '') {
            $query['search'] = $search;
        }

        $payload = HttpJsonCache::get(
            "https://mcjars.app/api/v3/builds/types/{$type}/versions",
            $this->listCacheSeconds,
            $query,
        );

        /** @var array<int|string, mixed> $wrapper */
        $wrapper = is_array($payload) ? ($payload['versions'] ?? []) : [];
        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [];

        $data = [];
        foreach ($rows as $row) {
            $versionType = strtoupper(trim((string) ($row['type'] ?? '')));

            if (!$snapshotsIn && in_array($versionType, ['SNAPSHOT', 'BETA'], true)) {
                continue;
            }

            $id = (string) ($row['id'] ?? $row['version_id'] ?? '');

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

        return [
            'total' => is_int($wrapper['total'] ?? null) ? $wrapper['total'] : (int) ($wrapper['total'] ?? count($data)),
            'page' => is_int($wrapper['page'] ?? null) ? $wrapper['page'] : max(1, $page),
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
        $type = Str::upper(trim($type));
        $version = rawurlencode(trim($version));

        if ($type === '' || $version === '') {
            return null;
        }

        $payload = HttpJsonCache::get(
            "https://mcjars.app/api/v3/builds/types/{$type}/versions/{$version}/latest",
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
                    'type' => (string) ($step['type'] ?? ''),
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
