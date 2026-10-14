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
 *   search(kind plugins|mods|modpacks, source?, q, page, compat(array{loader,mcVersion})?)
 *   → ['items'=>[shape], 'pages'=>?int total, 'sources_used'=>[], 'sources_skipped'=>[]]
 *
 * Every item is normalized to the contract shape
 * ['source','id','slug','title','author','description','downloads','likes','updated',
 *  'icon_url','page_url','loaders','game_versions','project_type'] plus a computed
 * boolean `compatible` (see below).
 */
final class ContentSearchService
{
    /** Enabled sources per kind (order = merge precedence when downloads tie). */
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
            throw_unless(in_array($requested, $wanted, true), new \InvalidArgumentException(
                "Source [{$requested}] does not serve the {$kind} catalog."
            ));

            $wanted = [$requested];
        }

        $items = [];
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

            foreach ($result['items'] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $item['compatible'] ??= true;
                $item['compatible'] = $this->computeCompatible($item, $compat);
                $items[] = $item;
            }

            $used[] = $one;

            if (is_int($result['pages']) && $result['pages'] > $pagesMax) {
                $pagesMax = $result['pages'];
            }
        }

        usort($items, fn (array $a, array $b): int => ((int) ($b['downloads'] ?? 0)) <=> ((int) ($a['downloads'] ?? 0)));

        return [
            'items' => $items,
            // Merged pagination is approximate when several registries contribute at once:
            // the loosest bound across used sources keeps "next page" always available.
            'pages' => $pagesMax > 0 || count($used) > 1 ? max(1, $pagesMax) : null,
            'sources_used' => $used,
            'sources_skipped' => $skipped,
        ];
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
            'curseforge' => $this->curseForge->search($mcVersion, self::classIdForKind($kind), $page),
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

        // OR-group per selected loader: [["categories:fabric"], ["categories:quilt"]] == fabric OR quilt.
        foreach (self::loadersEquivalent($loader) as $equivalent) {
            $facets[] = ["categories:{$equivalent}"];
        }

        if ($mcVersion !== '') {
            $facets[] = ["versions:{$mcVersion}"];
        }

        return $this->modrinth->search($projectType, $q, $page, $facets);
    }

    /**
     * Server-family map for local compat matching. Bukkit-style loaders, fabric/quilt pairs,
     * forge lineage and proxies collapse into families so e.g. a paper plugin counts as
     * compatible on purpur/folia servers etc.
     *
     * @return list<string>
     */
    private static function loadersEquivalent(string $loader): array
    {
        $loader = strtolower(trim($loader));

        return match (true) {
            in_array($loader, ['spigot', 'paper', 'bukkit', 'purpur', 'folia', 'leaves', 'pufferfish', 'mohist', 'arclight', 'sponge'], true) => [
                'paper', 'spigot', 'bukkit', 'purpur', 'folia', 'leaves', 'pufferfish', 'mohist', 'arclight', 'sponge',
            ],
            in_array($loader, ['fabric', 'quilt'], true) => ['fabric', 'quilt'],
            in_array($loader, ['forge', 'neoforge'], true) => ['forge', 'neoforge'],
            in_array($loader, ['velocity', 'waterfall', 'bungeecord'], true) => ['velocity', 'waterfall', 'bungeecord'],
            $loader !== '' && $loader !== 'unknown' => [$loader],
            default => [],
        };
    }

    /**
     * CurseForge class ids for each content kind.
     */
    private static function classIdForKind(string $kind): string
    {
        return match ($kind) {
            'plugins' => '12',
            'modpacks' => '4471',
            default => '6',
        };
    }

    /**
     * Compute the boolean `compatible` flag of one item against detection hints.
     *
     * Semantics:
     *  - no compat info given -> true (nothing constrained);
     *  - loader family intersection must be non-empty AND (when mc version known)
     *    `game_versions` includes it via plain string inclusion (exact match or prefix of
     *    range-ish strings like "1.21.x"). Mods/plugins that don't publish versions stay
     *    compatible=false only when their loader mismatches; MC-version matching is skipped
     *    for empty game_versions lists because most registries omit them.
     *
     * Known limitation (documented in handoff notes): modrinth rarely lists ranges; only
     * exact/prefix string containment is checked.
     *
     * @param  array<string, mixed>  $item
     */
    private function computeCompatible(array $item, ?array $compat): bool
    {
        if ($compat === null) {
            return true;
        }

        $family = self::loadersEquivalent((string) ($compat['loader'] ?? ''));
        $itemLoaders = array_map('strtolower', is_array($item['loaders'] ?? null) ? $item['loaders'] : []);

        if ($family !== [] && count(array_intersect($family, $itemLoaders)) === 0) {
            return false;
        }

        // Loader unknown -> it cannot invalidate anything; fall through to MC-version check.

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
            if (!is_string($version)) {
                continue;
            }

            $candidate = strtolower(trim($version));

            if ($candidate === $mcVersion || str_starts_with($candidate, $mcVersion.'.') || str_starts_with($candidate, $mcVersion.'-')) {
                return true;
            }

            // Prefix entries like "1.21.x" / "1.21" cover newer patches too.
            if (str_ends_with($candidate, '.x') && str_starts_with($mcVersion.'.', substr($candidate, 0, -2))) {
                return true;
            }
        }

        return false;
    }
}
