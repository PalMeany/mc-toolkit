<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use McToolkit\Services\Catalog\Registries\CurseForgeService;
use McToolkit\Services\Catalog\Registries\HangarService;
use McToolkit\Services\Catalog\Registries\ModrinthService;
use McToolkit\Services\Catalog\Registries\SpigetService;

/**
 * Unified multi-registry search facade. Owner: A3.
 *
 * Contract signature (frozen):
 *
 *   search(kind plugins|mods|modpacks, source?, q, page, compat(array{loader,mc_version})?)
 *   → ['items'=>[shape], 'pages'=>?int total, 'sources_used'=>[], 'sources_skipped'=>[]]
 *
 * Every item is normalized to the contract shape
 * ['source','id','slug','title','author','description','downloads','likes','updated',
 *  'icon_url','page_url','loaders','game_versions','project_type'] plus a computed
 * boolean `compatible` (see below).
 *
 * Ordering: each registry answers in its own relevance order, so several sources are
 * merged round-robin (1st of each, then 2nd of each, …) instead of re-sorting — the
 * pages' "Downloads" / "Newest" sorts are applied on top of that by the caller.
 */
final class ContentSearchService
{
    /** Enabled sources per kind (order = round-robin merge order). */
    private const SOURCES = [
        'plugins' => ['modrinth', 'hangar', 'spigot', 'curseforge'],
        'mods' => ['modrinth', 'curseforge'],
        // Modpack installs are Modrinth `.mrpack` flows; other registries ship zip bundles.
        'modpacks' => ['modrinth'],
    ];

    public function __construct(
        private readonly ModrinthService $modrinth,
        private readonly HangarService $hangar,
        private readonly SpigetService $spigot,
        private readonly CurseForgeService $curseForge,
    ) {}

    /**
     * Search across every enabled source for a kind (or restrict to one source).
     *
     * @param  string  $kind  plugins|mods|modpacks
     * @param  ?string  $source  restrict to one registry id, null = all enabled
     * @param  array{loader:?string, mc_version:?string}|null  $compat  server-fit hint from DetectionService
     * @return array{items:list<array<string, mixed>>, pages:?int, sources_used:list<string>, sources_skipped:array<string, string>}
     */
    public function search(string $kind, ?string $source = null, string $q = '', int $page = 1, ?array $compat = null): array
    {
        $kind = strtolower(trim($kind));

        if (!isset(self::SOURCES[$kind])) {
            throw new \InvalidArgumentException("Unsupported content kind [{$kind}].");
        }

        $wanted = array_values(self::SOURCES[$kind]);

        if ($source !== null) {
            $requested = strtolower(trim($source));
            // Spiget items carry 'spigotmc' while the chip key is 'spigot'.
            $requested = $requested === 'spigotmc' ? 'spigot' : $requested;

            throw_unless(in_array($requested, $wanted, true), new \InvalidArgumentException(
                "Source [{$requested}] does not serve the {$kind} catalog."
            ));

            $wanted = [$requested];
        }

        $perSource = [];
        $pagesMax = 0;
        $used = [];
        $skipped = [];

        foreach ($wanted as $one) {
            try {
                $result = $this->searchSingle($one, $kind, $q, $page, $compat);
            } catch (\Throwable $e) {
                $skipped[$one] = match (true) {
                    $e instanceof RequestException && optional($e->response)->status() === 429 => 'rate_limited',
                    $e instanceof ConnectionException,
                    $e instanceof RequestException => 'request_error',
                    default => 'request_error',
                };

                continue;
            }

            if ($result === null) {
                // Adapter-level "no key" — displayed to the user as missing credentials.
                $skipped[$one] = 'no_key';

                continue;
            }

            $bucket = [];

            foreach ($result['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $item['compatible'] = $this->computeCompatible($item, $compat);
                $bucket[] = $item;
            }

            $perSource[] = $bucket;
            $used[] = $one;

            if (is_int($result['pages']) && $result['pages'] > $pagesMax) {
                $pagesMax = $result['pages'];
            }
        }

        return [
            'items' => self::interleave($perSource),
            // Merged pagination is approximate when several registries contribute at once:
            // the loosest bound across used sources keeps "next page" available while any has more.
            'pages' => $pagesMax > 0 ? $pagesMax : null,
            'sources_used' => $used,
            'sources_skipped' => $skipped,
        ];
    }

    /**
     * Whether one advertised game version covers the server's version.
     *
     * Exact match, plus line-level tags: "1.21.x" and the major-only "1.21" (SpigotMC's
     * testedVersions only ever lists major lines) cover every 1.21.* patch release.
     */
    public static function gameVersionMatches(string $candidate, string $server): bool
    {
        $candidate = strtolower(trim($candidate));
        $server = strtolower(trim($server));

        if ($candidate === '' || $server === '') {
            return false;
        }

        if ($candidate === $server) {
            return true;
        }

        $line = str_ends_with($candidate, '.x') ? substr($candidate, 0, -2) : $candidate;

        // Only a two-segment line ("1.21") may stand for its patches; "1.21.1" never covers "1.21.10".
        if (substr_count($line, '.') !== 1) {
            return false;
        }

        return str_starts_with($server.'.', $line.'.');
    }

    /**
     * Server-family map for local compat matching. Bukkit-style loaders, fabric/quilt pairs,
     * forge lineage and proxies collapse into families so e.g. a paper plugin counts as
     * compatible on purpur/folia servers etc.
     *
     * @return list<string>
     */
    public static function loadersEquivalent(string $loader): array
    {
        $loader = strtolower(trim($loader));

        return match (true) {
            in_array($loader, ['spigot', 'paper', 'bukkit', 'purpur', 'folia', 'leaves', 'pufferfish', 'mohist', 'arclight'], true) => [
                'paper', 'spigot', 'bukkit', 'purpur', 'folia',
            ],
            in_array($loader, ['fabric', 'quilt'], true) => ['fabric', 'quilt'],
            in_array($loader, ['forge', 'neoforge'], true) => ['forge', 'neoforge'],
            in_array($loader, ['velocity', 'waterfall', 'bungeecord'], true) => ['velocity', 'waterfall', 'bungeecord'],
            $loader !== '' && $loader !== 'unknown' && $loader !== 'vanilla' => [$loader],
            default => [],
        };
    }

    /**
     * De-multiplex onto the concrete adapters and rebuild the uniform ['items','pages'] shape.
     *
     * @return array{items:array, pages:?int}|null null means "source not usable" (missing key)
     */
    private function searchSingle(string $source, string $kind, string $q, int $page, ?array $compat): ?array
    {
        $loader = strtolower(trim((string) ($compat['loader'] ?? '')));
        $mcVersion = trim((string) ($compat['mc_version'] ?? ''));

        return match ($source) {
            'modrinth' => $this->searchModrinth($kind, $q, $page, $loader, $mcVersion),
            'spigot' => $this->spigot->search($q, $page),
            'hangar' => $this->hangar->search($q, $loader, $page),
            'curseforge' => $this->curseForge->search(
                $mcVersion,
                self::classIdForKind($kind),
                $page,
                $q,
                $loader !== '' ? CurseForgeService::modLoaderTypeFor($loader) : null,
            ),
            default => throw new \LogicException("Unknown registry [{$source}]."),
        };
    }

    private function searchModrinth(string $kind, string $q, int $page, string $loader, string $mcVersion): array
    {
        $projectType = match ($kind) {
            'plugins' => 'plugin',
            'modpacks' => 'modpack',
            default => 'mod',
        };

        $facets = [];
        $equivalents = self::loadersEquivalent($loader);

        // ONE facet group = OR: [["categories:paper","categories:spigot",…]] means "any of them".
        // (Separate groups would AND every loader together and match nothing.)
        if ($equivalents !== []) {
            $facets[] = array_map(fn (string $equivalent): string => "categories:{$equivalent}", $equivalents);
        }

        if ($mcVersion !== '') {
            $facets[] = ["versions:{$mcVersion}"];
        }

        return $this->modrinth->search($projectType, $q, $page, $facets);
    }

    /**
     * CurseForge class ids for each content kind (432 = Minecraft).
     */
    private static function classIdForKind(string $kind): string
    {
        return match ($kind) {
            'plugins' => '5',     // Bukkit Plugins
            'modpacks' => '4471', // Modpacks
            default => '6',       // Mods
        };
    }

    /**
     * Round-robin merge keeping every source's own relevance order.
     *
     * @param  list<list<array<string, mixed>>>  $buckets
     * @return list<array<string, mixed>>
     */
    private static function interleave(array $buckets): array
    {
        $merged = [];
        $longest = 0;

        foreach ($buckets as $bucket) {
            $longest = max($longest, count($bucket));
        }

        for ($i = 0; $i < $longest; $i++) {
            foreach ($buckets as $bucket) {
                if (isset($bucket[$i])) {
                    $merged[] = $bucket[$i];
                }
            }
        }

        return $merged;
    }

    /**
     * Compute the boolean `compatible` flag of one item against detection hints.
     *
     * Semantics:
     *  - no compat info given -> true (nothing constrained);
     *  - the item's loaders must intersect the server family — an item that declares no
     *    loader at all is unknown, not incompatible;
     *  - with a known server version, one of `game_versions` must cover it
     *    ({@see self::gameVersionMatches()}); untagged items stay compatible.
     *
     * @param  array<string, mixed>  $item
     */
    private function computeCompatible(array $item, ?array $compat): bool
    {
        if ($compat === null) {
            return true;
        }

        $family = self::loadersEquivalent((string) ($compat['loader'] ?? ''));
        $itemLoaders = array_map(
            'strtolower',
            array_filter(is_array($item['loaders'] ?? null) ? $item['loaders'] : [], fn ($l) => is_string($l)),
        );

        if ($family !== [] && $itemLoaders !== [] && array_intersect($family, $itemLoaders) === []) {
            return false;
        }

        $mcVersion = trim((string) ($compat['mc_version'] ?? ''));

        if ($mcVersion === '') {
            return true;
        }

        $versions = is_array($item['game_versions'] ?? null) ? $item['game_versions'] : [];

        if ($versions === []) {
            // Registry didn't tag versions — treat as compatible rather than hiding results.
            return true;
        }

        foreach ($versions as $version) {
            if (is_string($version) && self::gameVersionMatches($version, $mcVersion)) {
                return true;
            }
        }

        return false;
    }
}
