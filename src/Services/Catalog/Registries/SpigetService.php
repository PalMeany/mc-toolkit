<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * Spiget registry adapter (https://api.spiget.org/v2). Owner: A3.
 *
 * Endpoint facts (verified against the live API):
 *  - GET /v2/search/resources/{urlencoded term}?size=25&page=N&fields=... -> list of resource objects
 *    (empty term falls back to the plain /v2/resources browse listing, sorted by newest update).
 *  - Pagination arrives in the `x-total` (matches) / `x-page-count` response headers.
 *  - `tag` is the one-line summary; `author` is only {id} (names need another call, skipped).
 *  - `icon.url` is RELATIVE to https://www.spigotmc.org/ ("data/resource_icons/…") or empty.
 *  - Premium and external resources cannot be fetched through /download, so they are
 *    dropped from the listing instead of offering an Install button that must fail.
 *  - SpigotMC resources are always Bukkit-family: loaders fixed to ['spigot','bukkit'].
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int].
 */
final class SpigetService
{
    private const API = 'https://api.spiget.org';

    private const SITE = 'https://www.spigotmc.org/';

    private const PAGE_SIZE = 25;

    private const FIELDS = [
        'id',
        'name',
        'tag',
        'downloads',
        'likes',
        'updateDate',
        'icon',
        'testedVersions',
        'premium',
        'external',
    ];

    public function __construct(private readonly int $listCacheSeconds = 300) {}

    /**
     * @return array{items:list<array<string, mixed>>, pages:?int}
     */
    public function search(string $q = '', int $page = 1): array
    {
        $term = trim($q);
        $page = max(1, $page);
        $query = [
            'size' => self::PAGE_SIZE,
            'page' => $page,
            'fields' => implode(',', self::FIELDS),
        ];

        if ($term === '') {
            $url = self::API.'/v2/resources';
            $query['sort'] = '-updateDate';
        } else {
            $url = self::API.'/v2/search/resources/'.rawurlencode($term);
        }

        // Transport or HTTP failures bubble up; ContentSearchService classifies them
        // (rate limit vs gateway-down) per source. Pagination lives in the headers, so
        // this one call skips the HttpJsonCache body-only channel.
        $response = Http::acceptJson()
            ->withUserAgent(HttpJsonCache::USER_AGENT)
            ->timeout(10)
            ->connectTimeout(5)
            ->retry(2, 100, fn (\Throwable $e): bool => $e instanceof ConnectionException
                || ($e instanceof RequestException && $e->response->serverError()))
            ->get($url, $query);

        $response->throw();

        /** @var mixed $rows */
        $rows = $response->json();

        if (!is_array($rows)) {
            return ['items' => [], 'pages' => null];
        }

        $items = [];

        foreach ($rows as $row) {
            if (!is_array($row) || ($row['premium'] ?? false) === true || ($row['external'] ?? false) === true) {
                continue;
            }

            $items[] = $this->normalize($row);
        }

        $total = trim($response->header('x-total'));
        $pageCount = trim($response->header('x-page-count'));

        $pages = match (true) {
            ctype_digit($total) && (int) $total > 0 => (int) ceil(((int) $total) / self::PAGE_SIZE),
            ctype_digit($pageCount) && (int) $pageCount > 0 => (int) $pageCount,
            default => null,
        };

        return [
            'items' => $items,
            'pages' => $pages !== null ? max(1, min(1000, $pages)) : null,
        ];
    }

    /**
     * Raw detail row for the installer (file type, premium/external flags, version id).
     *
     * @return array<string, mixed>|null
     */
    public function resource(int|string $resourceId): ?array
    {
        $payload = HttpJsonCache::get(self::API.'/v2/resources/'.rawurlencode((string) $resourceId), 120, [
            'fields' => implode(',', ['id', 'name', 'file', 'premium', 'external', 'version', 'updateDate', 'testedVersions']),
        ]);

        return is_array($payload) ? $payload : null;
    }

    /**
     * Human name of a resource's latest version ("2.20.1"), or null when unavailable.
     */
    public function latestVersionName(int|string $resourceId): ?string
    {
        try {
            $payload = HttpJsonCache::get(self::API.'/v2/resources/'.rawurlencode((string) $resourceId).'/versions/latest', 120);
        } catch (\Throwable) {
            return null;
        }

        $name = is_array($payload) && is_string($payload['name'] ?? null) ? trim($payload['name']) : '';

        return $name !== '' ? $name : null;
    }

    /**
     * Map one raw resource row onto the contract item shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function normalize(array $row): array
    {
        $title = trim((string) ($row['name'] ?? ''));
        $description = self::cleanTaggedText(is_string($row['tag'] ?? null) ? $row['tag'] : '');

        $gameVersions = array_values(array_filter(
            is_array($row['testedVersions'] ?? null) ? $row['testedVersions'] : [],
            fn ($v) => is_string($v) && $v !== '',
        ));

        $icon = is_array($row['icon'] ?? null) ? $row['icon'] : [];
        $iconPath = is_string($icon['url'] ?? null) ? trim($icon['url']) : '';
        $iconUrl = match (true) {
            $iconPath === '' => null,
            Str::startsWith($iconPath, ['http://', 'https://']) => $iconPath,
            default => self::SITE.ltrim($iconPath, '/'),
        };

        $updateDate = $row['updateDate'] ?? null;
        $updated = match (true) {
            is_int($updateDate), is_float($updateDate) => gmdate('c', (int) $updateDate),
            is_string($updateDate) && $updateDate !== '' => $updateDate,
            default => null,
        };

        $downloads = $row['downloads'] ?? 0;
        $likes = $row['likes'] ?? 0;
        $slug = Str::slug($title);
        $id = (string) ($row['id'] ?? '');

        return [
            'source' => 'spigotmc',
            'id' => $id,
            'slug' => $slug,
            'title' => $title,
            // Spiget only exposes the author id on resource rows.
            'author' => '',
            'description' => $description,
            'downloads' => is_numeric($downloads) ? (int) $downloads : 0,
            'likes' => is_numeric($likes) ? (int) $likes : 0,
            'updated' => $updated,
            'icon_url' => $iconUrl,
            'page_url' => $id !== ''
                ? sprintf('https://www.spigotmc.org/resources/%s.%s/', $slug ?: 'resource', $id)
                : null,
            // Every SpigotMC plugin runs on the bukkit family.
            'loaders' => ['spigot', 'bukkit'],
            'game_versions' => $gameVersions,
            'project_type' => 'plugin',
        ];
    }

    /**
     * Resource summaries may carry bbcode-like tags (`[COLOR=#ff0000]..[/COLOR]`, `<b>`,
     * entities); strip both dialects and collapse whitespace.
     */
    private static function cleanTaggedText(string $text): string
    {
        $text = preg_replace('/\[[a-z#\/][^\]]*\]/i', '', $text) ?? $text; // [TAG x] .. [/TAG]
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return Str::limit(trim($text), 300);
    }
}
