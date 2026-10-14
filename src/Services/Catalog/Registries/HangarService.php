<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * Hangar registry adapter (https://hangar.papermc.io/api/v1). Owner: A3.
 *
 * Facts:
 *  - Requires a Bearer token: config('minecraft-toolkit.hangar_key') / env HANGAR_KEY.
 *    `search()` returns null when no key is configured (source marked skipped, not an error).
 *  - GET /projects?limit=25&offset=(page-1)*25&sort=-stars&q=... -> {pagination:{...}, result:[{...}]}
 *  - Platforms are only authoritatively listed per-project; search rows are parsed for the
 *    known platform containers and mapped to loader chips ('paper','velocity','waterfall').
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int] or null.
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
        'BUNGEECORD' => 'bungeecord',
        'FOLIA' => 'folia',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /** Resolve the Bearer key; empty string means "not configured". */
    public function apiKey(): string
    {
        $key = config('minecraft-toolkit.hangar_key');

        return is_string($key) && $key !== '' ? $key : (string) env('HANGAR_KEY');
    }

    /**
     * @param  string  $loader  loader hint ('', 'paper', 'velocity', ...) — best effort server filter
     * @return array{items:list<array<string, mixed>>, pages:?int}|null null when no API key configured
     */
    public function search(string $q, string $loader = '', int $page = 1): ?array
    {
        if ($this->apiKey() === '') {
            return null;
        }

        $page = max(1, $page);
        $query = [
            'limit' => self::PAGE_SIZE,
            'offset' => ($page - 1) * self::PAGE_SIZE,
            'sort' => '-stars',
        ];

        if (trim($q) !== '') {
            $query['q'] = trim($q);
        }

        // Best-effort platform narrowing. If Hangar ignores unknown params this stays harmless;
        // results are re-filtered client-side anyway via the compat chip logic.
        if (trim($loader) !== '') {
            $query['platform'] = Str::upper($loader);
        }

        $payload = HttpJsonCache::get(self::API.'/projects', $this->listCacheSeconds, $query, [
            'Authorization' => 'Bearer '.$this->apiKey(),
        ]);

        if (!is_array($payload)) {
            return ['items' => [], 'pages' => null];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        $pages = null;
        $pagination = is_array($payload['pagination'] ?? null) ? $payload['pagination'] : [];
        $count = $pagination['count'] ?? null;

        if ((is_int($count) && $count > 0) || (is_numeric($count) && (int) $count > 0)) {
            // With a full page of results we cannot know the true total from one request:
            // report at least the current page and let the UI show "more available".
            $knownPages = is_int($pagination['offset'] ?? null)
                ? (int) floor(($pagination['offset'] + $count) / self::PAGE_SIZE)
                : $page;

            $fullPage = count($rows) >= self::PAGE_SIZE;

            $pages = max(1, min(400, $fullPage ? max($knownPages + 1, $page) : $knownPages));
        }

        return [
            'items' => array_values(array_map(fn (array $row) => $this->normalize($row), $rows)),
            'pages' => $pages,
        ];
    }

    /**
     * All published versions of one project (installer entry point).
     *
     * @return list<array<string, mixed>>
     */
    public function versions(string $ownerSlug): array
    {
        if ($this->apiKey() === '') {
            return [];
        }

        $payload = HttpJsonCache::get(
            self::API.'/projects/'.rawurlencode($ownerSlug).'/versions?limit=100&offset=0',
            120,
            [],
            ['Authorization' => 'Bearer '.$this->apiKey()],
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['result'] ?? null) ? $payload['result'] : [];

        return $rows;
    }

    /**
     * Map one raw project row onto the contract item shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $owner = is_array($row['namespace'] ?? null) ? $row['namespace'] : [];
        $name = is_string($owner['slug'] ?? null) ? $owner['slug'] : '';
        $user = is_string($owner['owner'] ?? null) ? $owner['owner'] : '';

        $stats = is_array($row['stats'] ?? null) ? $row['stats'] : [];

        $description = is_string($row['description'] ?? null)
            ? Str::limit(trim(strip_tags($row['description'])), 300)
            : '';

        $platforms = $this->extractPlatforms($row);

        $iconUrl = null;
        foreach ([['avatars', '150x150'], ['avatars', '64x64'], ['avatar_url'], []] as $path) {
            $node = $row;
            foreach ($path as $part) {
                $node = is_array($node[$part] ?? null) ? $node[$part] : [];
            }
            if (isset($node['url']) && is_string($node['url']) && $node['url'] !== '') {
                $iconUrl = $node['url'];
                break;
            }
        }

        $updated = match (true) {
            is_string($row['last_updated'] ?? null) => $row['last_updated'],
            is_string($row['lastUpdated'] ?? null) => $row['lastUpdated'],
            default => null,
        };

        $downloads = $stats['downloads'] ?? 0;
        $likes = $stats['stars'] ?? 0;

        return [
            'source' => 'hangar',
            'id' => ($user !== '' && $name !== '') ? "{$user}/{$name}" : $name,
            'slug' => $name,
            'title' => (string) ($row['name'] ?? ''),
            'author' => $user,
            'description' => $description,
            'downloads' => is_int($downloads) ? $downloads : (int) $downloads,
            'likes' => is_int($likes) ? $likes : (int) $likes,
            'updated' => $updated,
            'icon_url' => $iconUrl,
            'page_url' => ($user !== '' && $name !== '') ? "https://hangar.papermc.io/{$user}/{$name}" : null,
            'loaders' => $platforms ?: ['paper'],
            // Hangar does not expose game_versions in search rows; chips stay server-side only.
            'game_versions' => [],
            'project_type' => 'plugin',
        ];
    }

    /**
     * Search rows have no guaranteed platform container; probe common locations and map
     * to loader chips. Falls back to paper (Hangar's dominant ecosystem).
     *
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private function extractPlatforms(array $row): array
    {
        $candidates = [
            $row['approvedPlatforms'] ?? null,
            $row['settings']['platforms'] ?? null,
            $row['platforms'] ?? null,
            $row['category'] ?? null,
        ];

        $loaders = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate)) {
                $upper = Str::upper($candidate);

                if (isset(self::PLATFORM_MAP[$upper])) {
                    $loaders[self::PLATFORM_MAP[$upper]] = true;
                }

                continue;
            }

            foreach (is_array($candidate) ? array_keys($candidate) : [] as $platform) {
                $upper = Str::upper((string) $platform);

                if (isset(self::PLATFORM_MAP[$upper])) {
                    $loaders[self::PLATFORM_MAP[$upper]] = true;
                }
            }
        }

        return array_values(array_unique(array_keys($loaders)));
    }
}
