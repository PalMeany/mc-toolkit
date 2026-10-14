<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * CurseForge registry adapter (https://api.curseforge.com/v1). Owner: A3.
 *
 * Facts:
 *  - Requires the x-api-key header; config('minecraft-toolkit.curseforge_key') / env CURSEFORGE_KEY.
 *    `search()` returns null when no key is configured (source marked skipped, not an error).
 *  - GET /mods/search?gameId=432&classId=<class>&gameVersion=<mc>&searchFilter=&sortField=2
 *    &sortOrder=desc&pageSize=25&index=(page-1)*25 -> {data:[...], meta:{totalCount:int}}
 *    classId: 6 = mods, 12 = plugins (bukkit addons), 4471 = modpacks.
 *  - Mod rows carry categories with slug names matching loader keywords and latestFilesIndexes
 *    carrying gameVersion values — used to build the uniform loaders/game_versions chips.
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int] or null.
 */
final class CurseForgeService
{
    private const API = 'https://api.curseforge.com';

    private const PAGE_SIZE = 25;

    private const GAME_ID_MINECRAFT = 432;

    /** CurseForge category slugs mapped onto loader chips. */
    private const CATEGORY_LOADER_MAP = [
        'forge' => 'forge',
        'neoforge' => 'neoforge',
        'fabric' => 'fabric',
        'quilt' => 'quilt',
        'bukkit' => 'bukkit',
        'spigot' => 'spigot',
        'paper' => 'paper',
        'purpur' => 'purpur',
        'folia' => 'folia',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /** Resolve the x-api-key; empty string means "not configured". */
    public function apiKey(): string
    {
        $key = config('minecraft-toolkit.curseforge_key');

        return is_string($key) && $key !== '' ? $key : (string) env('CURSEFORGE_KEY');
    }

    /**
     * @param  string  $gameVersion  exact Minecraft version filter ('' disables it)
     * @param  string  $classId  '6'|'12'|'4471'
     * @return array{items:list<array<string, mixed>>, pages:?int}|null null when no API key configured
     */
    public function search(string $gameVersion, string $classId, int $page = 1): ?array
    {
        if ($this->apiKey() === '') {
            return null;
        }

        $query = [
            'gameId' => self::GAME_ID_MINECRAFT,
            'classId' => $classId,
            'searchFilter' => '',
            'sortField' => 2, // popularity — id 2 on CF's SortField enum
            'sortOrder' => 'desc',
            'pageSize' => self::PAGE_SIZE,
            'index' => max(0, ($page - 1) * self::PAGE_SIZE),
        ];

        if (trim($gameVersion) !== '') {
            $query['gameVersion'] = trim($gameVersion);
        }

        $payload = HttpJsonCache::get(self::API.'/v1/mods/search', $this->listCacheSeconds, $query, [
            'x-api-key' => $this->apiKey(),
        ]);

        if (!is_array($payload)) {
            return ['items' => [], 'pages' => null];
        }

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $total = $payload['meta']['totalCount'] ?? null;

        $type = match ($classId) {
            '12', 12 => 'plugin',
            '4471', 4471 => 'modpack',
            default => 'mod',
        };

        return [
            'items' => array_values(array_map(fn (array $row) => $this->normalize($row, $type), $rows)),
            // Meta totalCount can grow past any sane pagination budget.
            'pages' => (is_int($total) && $total > 0)
                ? max(1, min(400, (int) ceil($total / self::PAGE_SIZE)))
                : null,
        ];
    }

    /**
     * File listing for one mod (installer entry point).
     *
     * @return list<array<string, mixed>>
     */
    public function files(int|string $modId): array
    {
        if ($this->apiKey() === '') {
            return [];
        }

        $payload = HttpJsonCache::get(
            self::API.'/v1/mods/'.rawurlencode((string) $modId).'/files',
            120,
            [],
            ['x-api-key' => $this->apiKey()],
        );

        /** @var list<array<string, mixed>> $rows */
        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return $rows;
    }

    /**
     * Map one raw mod row onto the contract item shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row, string $projectType): array
    {
        $title = trim((string) ($row['name'] ?? ''));

        $summary = is_string($row['summary'] ?? null) ? $row['summary'] : '';
        $description = Str::limit(trim(strip_tags(html_entity_decode($summary, ENT_QUOTES | ENT_HTML5, 'UTF-8'))), 300);

        $categories = is_array($row['categories'] ?? null) ? $row['categories'] : [];
        $loaders = [];
        foreach ($categories as $category) {
            $slug = is_array($category) && isset($category['slug']) && is_string($category['slug'])
                ? strtolower($category['slug'])
                : '';

            if (isset(self::CATEGORY_LOADER_MAP[$slug])) {
                $loaders[self::CATEGORY_LOADER_MAP[$slug]] = true;
            }
        }

        // game_versions derived from latestFilesIndexes (unique, order preserved).
        $indexes = is_array($row['latestFilesIndexes'] ?? null) ? $row['latestFilesIndexes'] : [];
        $gameVersions = [];
        foreach ($indexes as $index) {
            if (is_array($index) && is_string($index['gameVersion'] ?? null)) {
                $gameVersions[$index['gameVersion']] = true;
            }
        }

        $logo = is_array($row['logo'] ?? null) ? $row['logo'] : [];
        $iconUrl = is_string($logo['thumbnailUrl'] ?? null) && $logo['thumbnailUrl'] !== ''
            ? $logo['thumbnailUrl']
            : (is_string($logo['url'] ?? null) ? $logo['url'] : null);

        $links = is_array($row['links'] ?? null) ? $row['links'] : [];
        $pageUrl = is_string($links['websiteUrl'] ?? null) && $links['websiteUrl'] !== ''
            ? $links['websiteUrl']
            : null;

        $downloads = $row['downloadCount'] ?? 0;
        $likes = $row['thumbsUpCount'] ?? 0;

        $updated = match (true) {
            is_string($row['dateModified'] ?? null) && $row['dateModified'] !== '' => $row['dateModified'],
            default => null,
        };

        $id = $row['id'] ?? null;

        return [
            'source' => 'curseforge',
            'id' => is_int($id) ? (string) $id : ((string) ($id ?? ($row['slug'] ?? ''))),
            'slug' => is_string($row['slug'] ?? null) ? $row['slug'] : Str::slug($title),
            'title' => $title,
            // CurseForge search rows have no author object; the class owner is unknown here.
            'author' => '',
            'description' => $description,
            'downloads' => is_int($downloads) ? $downloads : (int) $downloads,
            'likes' => is_int($likes) ? $likes : (int) $likes,
            'updated' => $updated,
            'icon_url' => $iconUrl,
            'page_url' => $pageUrl,
            // Bukkit-family classes have no explicit loader chips when categories are sparse.
            'loaders' => array_values($loaders),
            'game_versions' => array_keys($gameVersions),
            'project_type' => $projectType,
        ];
    }
}
