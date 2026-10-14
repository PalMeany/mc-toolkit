<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * Hangar registry adapter (https://hangar.papermc.io/api/v1). Owner: A3.
 *
 * Facts (verified against the live API):
 *  - Read endpoints are public — no key or token is needed (Hangar's own auth is a
 *    JWT exchanged via /authenticate, never a raw Bearer key, so none is sent).
 *  - GET /projects?limit&offset&sort=-stars&query=…&platform=PAPER
 *      -> {pagination:{count (TOTAL), limit, offset}, result:[project]}
 *    project: {name, namespace:{owner, slug}, description, avatarUrl, lastUpdated,
 *              stats:{downloads, stars, …}, supportedPlatforms:{PAPER:["1.20.6", …]}}
 *  - GET /projects/{slug}/versions?limit&offset — the SLUG alone; "Owner%2FSlug" is a 400.
 *    version: {name, createdAt, channel:{name, flags?}, downloads:{PAPER:{fileInfo:{name,
 *              sizeBytes, sha256Hash}|null, externalUrl, downloadUrl|null}},
 *              platformDependencies:{PAPER:["1.20.6", …]}}
 *  - Platforms are PAPER, VELOCITY and WATERFALL (Folia ships as PAPER builds).
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int].
 */
final class HangarService
{
    private const API = 'https://hangar.papermc.io/api/v1';

    private const PAGE_SIZE = 25;

    /** Hangar platform names that map onto loader chips. */
    private const PLATFORM_MAP = [
        'PAPER' => 'paper',
        'VELOCITY' => 'velocity',
        'WATERFALL' => 'waterfall',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /** Search pages honour config('minecraft-toolkit.cache_ttl.searches'); the constructor value is the fallback. */
    private function searchTtl(): int
    {
        return max(0, (int) config('minecraft-toolkit.cache_ttl.searches', $this->listCacheSeconds));
    }

    /**
     * Hangar platform matching one loader token ('' when Hangar has no such platform).
     */
    public static function platformFor(string $loader): string
    {
        return match (strtolower(trim($loader))) {
            'paper', 'purpur', 'folia', 'spigot', 'bukkit', 'pufferfish', 'leaves' => 'PAPER',
            'velocity' => 'VELOCITY',
            'waterfall', 'bungeecord' => 'WATERFALL',
            default => '',
        };
    }

    /**
     * @param  string  $loader  loader hint ('', 'paper', 'velocity', ...) — narrows by platform when Hangar knows it
     * @return array{items:list<array<string, mixed>>, pages:?int}
     */
    public function search(string $q, string $loader = '', int $page = 1): array
    {
        $page = max(1, $page);
        $query = [
            'limit' => self::PAGE_SIZE,
            'offset' => ($page - 1) * self::PAGE_SIZE,
            'sort' => '-stars',
        ];

        if (trim($q) !== '') {
            $query['query'] = trim($q);
        }

        // Only real Hangar platforms are sent; an unknown enum value would fail the request.
        $platform = self::platformFor($loader);

        if ($platform !== '') {
            $query['platform'] = $platform;
        }

        $payload = HttpJsonCache::get(self::API.'/projects', $this->searchTtl(), $query);

        if (!is_array($payload)) {
            return ['items' => [], 'pages' => null];
        }

        $rows = is_array($payload['result'] ?? null) ? $payload['result'] : [];
        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
        $total = is_numeric($pagination['count'] ?? null) ? (int) $pagination['count'] : null;

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->normalize($row);
            }
        }

        return [
            'items' => $items,
            // `count` is the TOTAL number of matches, not the size of this page.
            'pages' => $total !== null && $total > 0
                ? max(1, min(400, (int) ceil($total / self::PAGE_SIZE)))
                : null,
        ];
    }

    /**
     * Newest published versions of one project (installer + update scanner entry point).
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $slug): array
    {
        $slug = trim($slug);

        // Legacy ids were stored as "Owner/Slug"; the versions route takes the slug alone.
        if (str_contains($slug, '/')) {
            $slug = (string) Str::afterLast($slug, '/');
        }

        if ($slug === '') {
            return [];
        }

        $payload = HttpJsonCache::get(
            self::API.'/projects/'.rawurlencode($slug).'/versions',
            120,
            ['limit' => self::PAGE_SIZE, 'offset' => 0],
        );

        $rows = is_array($payload) && is_array($payload['result'] ?? null) ? $payload['result'] : [];

        return array_values(array_filter($rows, fn ($row) => is_array($row)));
    }

    /**
     * Map one raw project row onto the contract item shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $namespace = is_array($row['namespace'] ?? null) ? $row['namespace'] : [];
        $slug = is_string($namespace['slug'] ?? null) ? $namespace['slug'] : '';
        $owner = is_string($namespace['owner'] ?? null) ? $namespace['owner'] : '';

        $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];

        $description = is_string($row['description'] ?? null)
            ? Str::limit(trim(strip_tags($row['description'])), 300)
            : '';

        $loaders = [];
        $gameVersions = [];
        $platforms = is_array($row['supportedPlatforms'] ?? null) ? $row['supportedPlatforms'] : [];

        foreach ($platforms as $platform => $versions) {
            $upper = strtoupper((string) $platform);

            if (isset(self::PLATFORM_MAP[$upper])) {
                $loaders[self::PLATFORM_MAP[$upper]] = true;
            }

            // Server-side game versions only matter for the PAPER family here.
            if ($upper === 'PAPER' && is_array($versions)) {
                foreach ($versions as $version) {
                    if (is_string($version) && $version !== '') {
                        $gameVersions[$version] = true;
                    }
                }
            }
        }

        $iconUrl = is_string($row['avatarUrl'] ?? null) && $row['avatarUrl'] !== '' ? $row['avatarUrl'] : null;
        $updated = is_string($row['lastUpdated'] ?? null) ? $row['lastUpdated'] : null;

        $downloads = $stats['downloads'] ?? 0;
        $likes = $stats['stars'] ?? 0;

        return [
            'source' => 'hangar',
            // The slug is unique on Hangar and is what the versions route takes.
            'id' => $slug,
            'slug' => $slug,
            'title' => (string) ($row['name'] ?? $slug),
            'author' => $owner,
            'description' => $description,
            'downloads' => is_numeric($downloads) ? (int) $downloads : 0,
            'likes' => is_numeric($likes) ? (int) $likes : 0,
            'updated' => $updated,
            'icon_url' => $iconUrl,
            'page_url' => ($owner !== '' && $slug !== '') ? "https://hangar.papermc.io/{$owner}/{$slug}" : null,
            'loaders' => array_keys($loaders) ?: ['paper'],
            'game_versions' => array_keys($gameVersions),
            'project_type' => 'plugin',
        ];
    }
}
