<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * Modrinth registry adapter (https://api.modrinth.com/v2). Owner: A3.
 *
 * Endpoint: GET /v2/search with `facets` as a JSON list of lists —
 * each inner list is an OR group, the outer list is AND. Only 2xx JSON is
 * handled; transport/HTTP errors bubble so the aggregator can mark the source.
 *
 * Return shape (used by ContentSearchService):
 *   ['items' => list<normalized-item>, 'pages' => ?int]
 */
final class ModrinthService
{
    private const API = 'https://api.modrinth.com/v2';

    private const PAGE_SIZE = 25;

    /** Search result rows ship these exact fields; unknown extra fields are ignored. */
    private const FIELDS = [
        'project_id', 'slug', 'author', 'title', 'description', 'categories', 'display_categories',
        'versions', 'downloads', 'follows', 'date_created', 'date_modified', 'icon_url',
        'project_type', 'loaders',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /**
     * @param  string  $projectType  modrinth project type: plugin|mod|modpack
     * @param  array<int, list<string>>  $facets  extra OR groups appended after the base facets
     * @return array{items:list<array<string, mixed>>, pages:?int}
     */
    public function search(string $projectType, string $query = '', int $page = 1, array $facets = []): array
    {
        $baseFacets = [["project_type:{$projectType}"]];
        $facetParam = json_encode([...$baseFacets, ...array_values($facets)], JSON_UNESCAPED_SLASHES);

        $payload = HttpJsonCache::get(self::API.'/search', $this->listCacheSeconds, [
            'limit' => self::PAGE_SIZE,
            'offset' => max(0, ($page - 1) * self::PAGE_SIZE),
            'index' => 'relevance',
            'query' => $query,
            'facets' => (string) $facetParam,
        ]);

        if (!is_array($payload)) {
            return ['items' => [], 'pages' => null];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['hits'] ?? null) ? $payload['hits'] : [];
        /** @var int|null $totalHits */
        $totalHits = is_int($payload['total_hits'] ?? null) ? $payload['total_hits'] : null;

        return [
            'items' => array_values(array_map(fn (array $row) => $this->normalize($row), $rows)),
            // `total_hits` also counts older projects; cap at a sane bound for pagination UIs.
            'pages' => is_null($totalHits) ? null : max(1, min(400, (int) ceil($totalHits / self::PAGE_SIZE))),
        ];
    }

    /**
     * Fetch ALL versions of one modrinth project (newest first upstream) — used by the installer.
     *
     * Optional `$query` carries upstream filter params, e.g.
     *   ['loaders' => '["paper"]', 'game_versions' => '["1.21.3"]']
     *
     * @param  string  $projectIdOrSlug
     * @return list<array<string, mixed>>
     */
    public function versions(string $projectIdOrSlug, array $query = []): array
    {
        $payload = HttpJsonCache::get(
            self::API.'/project/'.rawurlencode($projectIdOrSlug).'/version',
            60,
            $query,
        );

        if (!is_array($payload)) {
            return [];
        }

        return array_values(array_filter($payload, fn ($v) => is_array($v)));
    }

    /**
     * Map one raw search row to the contract item shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $type = strtolower(trim((string) ($row['project_type'] ?? '')));
        $type = in_array($type, ['plugin', 'mod', 'modpack'], true) ? $type : 'other';

        $loaders = array_values(array_filter(
            is_array($row['loaders'] ?? null) ? $row['loaders'] : [],
            fn ($l) => is_string($l) && $l !== '',
        ));
        $gameVersions = array_values(array_filter(
            is_array($row['versions'] ?? null) ? $row['versions'] : [],
            fn ($v) => is_string($v) && $v !== '',
        ));

        $description = is_string($row['description'] ?? null) ? self::cleanText($row['description']) : '';

        $pageUrl = null;
        $slug = is_string($row['slug'] ?? null) ? $row['slug'] : null;

        if ($slug !== null && $slug !== '') {
            $segment = match ($type) { 'plugin', 'modpack' => $type, default => 'mod' };
            $pageUrl = "https://modrinth.com/{$segment}/{$slug}";
        }

        $id = is_string($row['project_id'] ?? null) ? $row['project_id'] : null;

        return [
            'source' => 'modrinth',
            'id' => $id ?? ((string) ($slug ?? '')),
            'slug' => $slug ?? '',
            'title' => (string) ($row['title'] ?? ''),
            'author' => (string) ($row['author'] ?? ''),
            'description' => $description,
            'downloads' => is_int($row['downloads'] ?? null) ? $row['downloads'] : (int) ($row['downloads'] ?? 0),
            'likes' => is_int($row['follows'] ?? null) ? $row['follows'] : (int) ($row['follows'] ?? 0),
            'updated' => is_string($row['date_modified'] ?? null) ? $row['date_modified'] : null,
            'icon_url' => is_string($row['icon_url'] ?? null) ? $row['icon_url'] : null,
            'page_url' => $pageUrl,
            'loaders' => array_map('strtolower', $loaders),
            'game_versions' => $gameVersions,
            'project_type' => $type,
        ];
    }

    /** HTML-strip + collapse whitespace + trim; descriptions are plain text chips in the UI. */
    private static function cleanText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit(trim($text), 300);
    }
}
