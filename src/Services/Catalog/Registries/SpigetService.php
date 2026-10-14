<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog\Registries;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use McToolkit\Support\HttpJsonCache;

/**
 * Spiget registry adapter (https://api.spiget.org/v2). Owner: A3.
 *
 * Endpoint facts:
 *  - GET /v2/search/resources/{urlencoded term}?size=25&page=N&fields=... -> list of resource objects
 *    (empty term falls back to the plain /v2/resources browse listing, sorted by newest update).
 *  - Resources carry `author` nested {id, name}; it only appears when `fields=author` is requested.
 *  - `default:tag` (the description bbcode-ish blob) must be cleaned before display.
 *  - SpigotMC resources are always Bukkit-family: loaders fixed to ['spigot','bukkit'].
 *  - Pagination totals arrive in the `X-Pagination-Total-Results-Count` response header;
 *    when absent the returned `pages` is null.
 *
 * Return shape used by ContentSearchService: ['items' => list<item>, 'pages' => ?int].
 */
final class SpigetService
{
    private const API = 'https://api.spiget.org';

    private const PAGE_SIZE = 25;

    private const FIELDS = [
        'name',
        'default:tag',
        'author',
        'downloads',
        'likes',
        'updateDate',
        'icon',
        'testedVersions',
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
        // (rate limit vs gateway-down) per source.
        $response = Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->retries(2, 100)
            ->get($url, $query);

        $response->throw();

        /** @var mixed $rows */
        $rows = $response->json();

        if (!is_array($rows)) {
            return ['items' => [], 'pages' => null];
        }

        /** @var list<array<string, mixed>> $list */
        $list = array_values(array_filter($rows, fn ($row) => is_array($row)));

        // Header value arrives on the primary request; sanitize aggressively.
        $headerValue = $response->toPsrResponse()->getHeaderLine('X-Pagination-Total-Results-Count');
        $total = ctype_digit(trim($headerValue)) ? (int) trim($headerValue) : null;

        return [
            'items' => array_values(array_map(fn (array $row) => $this->normalize($row), $list)),
            'pages' => (!is_null($total) && $total > 0)
                ? max(1, min(1000, (int) ceil($total / self::PAGE_SIZE)))
                : null,
        ];
    }

    /**
     * Raw detail row for the installer (includes the file object + last update).
     *
     * @return array<string, mixed>|null
     */
    public function resource(int|string $resourceId): ?array
    {
        $payload = HttpJsonCache::get(self::API.'/v2/resources/'.rawurlencode((string) $resourceId), 120, [
            'fields' => implode(',', ['name', 'downloads', 'likes', 'updateDate', 'testedVersions', 'file']),
        ]);

        return is_array($payload) ? $payload : null;
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
        $description = self::cleanTaggedText(is_string($row['default:tag'] ?? null) ? $row['default:tag'] : '');

        $gameVersions = array_values(array_filter(
            is_array($row['testedVersions'] ?? null) ? $row['testedVersions'] : [],
            fn ($v) => is_string($v) && $v !== '',
        ));

        $icon = is_array($row['icon'] ?? null) ? $row['icon'] : [];
        $iconUrl = is_string($icon['url'] ?? null) ? $icon['url'] : null;
        if (is_string($iconUrl) && Str::startsWith($iconUrl, '/')) {
            $iconUrl = self::API.$iconUrl;
        }

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
            'author' => is_string($row['author']['name'] ?? null) ? $row['author']['name'] : '',
            'description' => $description,
            'downloads' => is_int($downloads) ? $downloads : (int) $downloads,
            'likes' => is_int($likes) ? $likes : (int) $likes,
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
     * Resource descriptions are bbcode-like HTML blobs (`[COLOR=#ff0000]..[/COLOR]`, `<b>`,
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
