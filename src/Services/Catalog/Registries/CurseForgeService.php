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
 *  - GET /mods/search?gameId=432&classId=<class>&searchFilter=<q>&gameVersion=<mc>&modLoaderType=<n>
 *    &sortField=2&sortOrder=desc&pageSize=25&index=(page-1)*25 -> {data:[...], meta:{totalCount:int}}
 *    classId: 6 = mods, 5 = Bukkit plugins, 4471 = modpacks (12 is RESOURCE PACKS).
 *  - Loaders come from latestFilesIndexes[].modLoader (ModLoaderType: 1 Forge, 4 Fabric,
 *    5 Quilt, 6 NeoForge); content categories never name loaders.
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int] or null.
 */
final class CurseForgeService
{
    private const API = 'https://api.curseforge.com';

    private const PAGE_SIZE = 25;

    private const GAME_ID_MINECRAFT = 432;

    /** ModLoaderType enum ids onto loader chips. */
    private const MOD_LOADER_MAP = [
        1 => 'forge',
        4 => 'fabric',
        5 => 'quilt',
        6 => 'neoforge',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /** Search pages honour config('minecraft-toolkit.cache_ttl.searches'); the constructor value is the fallback. */
    private function searchTtl(): int
    {
        return max(0, (int) config('minecraft-toolkit.cache_ttl.searches', $this->listCacheSeconds));
    }

    /** Resolve the x-api-key; empty string means "not configured". */
    public function apiKey(): string
    {
        $key = config('minecraft-toolkit.curseforge_key');

        return is_string($key) && $key !== '' ? $key : (string) env('CURSEFORGE_KEY');
    }

    /**
     * ModLoaderType id for one loader token, or null when CurseForge has none.
     */
    public static function modLoaderTypeFor(string $loader): ?int
    {
        $id = array_search(strtolower(trim($loader)), self::MOD_LOADER_MAP, true);

        return is_int($id) ? $id : null;
    }

    /**
     * @param  string  $gameVersion  exact Minecraft version filter ('' disables it)
     * @param  string  $classId  '6'|'5'|'4471'
     * @param  string  $query  free-text search ('' = popularity listing)
     * @param  int|null  $modLoaderType  ModLoaderType filter (null disables it)
     * @return array{items:list<array<string, mixed>>, pages:?int}|null null when no API key configured
     */
    public function search(string $gameVersion, string $classId, int $page = 1, string $query = '', ?int $modLoaderType = null): ?array
    {
        if ($this->apiKey() === '') {
            return null;
        }

        $params = [
            'gameId' => self::GAME_ID_MINECRAFT,
            'classId' => $classId,
            'sortField' => 2, // popularity — id 2 on CF's ModsSearchSortField enum
            'sortOrder' => 'desc',
            'pageSize' => self::PAGE_SIZE,
            'index' => max(0, ($page - 1) * self::PAGE_SIZE),
        ];

        if (trim($query) !== '') {
            $params['searchFilter'] = trim($query);
        }

        if (trim($gameVersion) !== '') {
            $params['gameVersion'] = trim($gameVersion);
        }

        if ($modLoaderType !== null) {
            $params['modLoaderType'] = $modLoaderType;
        }

        $payload = HttpJsonCache::get(self::API.'/v1/mods/search', $this->searchTtl(), $params, [
            'x-api-key' => $this->apiKey(),
        ]);

        if (!is_array($payload)) {
            return ['items' => [], 'pages' => null];
        }

        $rows = is_array($payload['data'] ?? null) ? $payload['data'] : [];

        $total = $payload['meta']['totalCount'] ?? null;

        $type = match ($classId) {
            '5' => 'plugin',
            '4471' => 'modpack',
            default => 'mod',
        };

        $items = [];

        foreach ($rows as $row) {
            if (is_array($row)) {
                $items[] = $this->normalize($row, $type);
            }
        }

        return [
            'items' => $items,
            // CurseForge refuses index + pageSize beyond 10 000, i.e. 400 pages of 25.
            'pages' => (is_int($total) && $total > 0)
                ? max(1, min(400, (int) ceil($total / self::PAGE_SIZE)))
                : null,
        ];
    }

    /**
     * File listing for one mod (installer entry point), newest uploads first upstream.
     *
     * @param  array<string, scalar>  $filters  gameVersion / modLoaderType narrowing
     * @return list<array<string, mixed>>
     */
    public function files(int|string $modId, array $filters = []): array
    {
        if ($this->apiKey() === '') {
            return [];
        }

        $payload = HttpJsonCache::get(
            self::API.'/v1/mods/'.rawurlencode((string) $modId).'/files',
            120,
            ['pageSize' => 50, ...$filters],
            ['x-api-key' => $this->apiKey()],
        );

        $rows = is_array($payload) && is_array($payload['data'] ?? null) ? $payload['data'] : [];

        return array_values(array_filter($rows, fn ($row) => is_array($row)));
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

        $loaders = [];
        $gameVersions = [];

        foreach (is_array($row['latestFilesIndexes'] ?? null) ? $row['latestFilesIndexes'] : [] as $index) {
            if (!is_array($index)) {
                continue;
            }

            if (is_string($index['gameVersion'] ?? null) && $index['gameVersion'] !== '') {
                $gameVersions[$index['gameVersion']] = true;
            }

            $loaderType = $index['modLoader'] ?? null;

            if (is_int($loaderType) && isset(self::MOD_LOADER_MAP[$loaderType])) {
                $loaders[self::MOD_LOADER_MAP[$loaderType]] = true;
            }
        }

        // Bukkit-class projects never carry a mod loader; they all target the Bukkit API.
        if ($projectType === 'plugin') {
            $loaders = ['bukkit' => true, 'spigot' => true];
        }

        $logo = is_array($row['logo'] ?? null) ? $row['logo'] : [];
        $iconUrl = is_string($logo['thumbnailUrl'] ?? null) && $logo['thumbnailUrl'] !== ''
            ? $logo['thumbnailUrl']
            : (is_string($logo['url'] ?? null) && $logo['url'] !== '' ? $logo['url'] : null);

        $links = is_array($row['links'] ?? null) ? $row['links'] : [];
        $pageUrl = is_string($links['websiteUrl'] ?? null) && $links['websiteUrl'] !== ''
            ? $links['websiteUrl']
            : null;

        $authors = is_array($row['authors'] ?? null) ? $row['authors'] : [];
        $firstAuthor = is_array($authors[0] ?? null) ? $authors[0] : [];

        $downloads = $row['downloadCount'] ?? 0;
        $likes = $row['thumbsUpCount'] ?? 0;

        $updated = is_string($row['dateModified'] ?? null) && $row['dateModified'] !== '' ? $row['dateModified'] : null;

        $id = $row['id'] ?? null;

        return [
            'source' => 'curseforge',
            'id' => is_int($id) ? (string) $id : (string) ($id ?? ''),
            'slug' => is_string($row['slug'] ?? null) ? $row['slug'] : Str::slug($title),
            'title' => $title,
            'author' => is_string($firstAuthor['name'] ?? null) ? $firstAuthor['name'] : '',
            'description' => $description,
            'downloads' => is_numeric($downloads) ? (int) $downloads : 0,
            'likes' => is_numeric($likes) ? (int) $likes : 0,
            'updated' => $updated,
            'icon_url' => $iconUrl,
            'page_url' => $pageUrl,
            'loaders' => array_keys($loaders),
            'game_versions' => array_keys($gameVersions),
            'project_type' => $projectType,
        ];
    }
}
