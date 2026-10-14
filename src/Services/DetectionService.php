<?php

declare(strict_types=1);

namespace McToolkit\Services;

use App\Models\Server;

/**
 * Filesystem heuristics that figure out WHAT is installed on a server:
 * vanilla / Paper fork / Spigot / Fabric / Quilt / Forge / NeoForge / mohist /
 * sponge / a Velocity-waterfall-BungeeCord proxy, plus the content folders each
 * flavor uses (parity item from the original addon's "Paper 1.21.3 · Detected
 * from the files" banner).
 *
 * Honesty note (v1 scope): the java major version is NOT resolved — it needs either
 * an egg-variable read (names differ per egg) or runtime log parsing, both out of v1.
 * `java` stays null until a dedicated bridge or log scan lands. We also never unzip
 * jars: every match relies purely on FILE NAMES and top-level directories, which all
 * standard egg layouts produce.
 *
 * Strategy = one `FileBridge::ls('/')` pass + at most one extra `/libraries/net`
 * probe to split Forge vs NeoForge when a libraries tree exists but no jar did.
 *
 * Marker table (first matching rule per file wins; highest weight wins overall):
 *
 * | pattern hint                                  | software    | weight |
 * |-----------------------------------------------|-------------|--------|
 * | `velocity*.jar`                               | velocity ⭑  | 0.90   |
 * | `neoforge*`                                   | neoforge    | 0.90   |
 * | `forge-*(.jar|-installer)`                    | forge       | 0.85   |
 * | `purpur*`                                     | purpur      | 0.85   |
 * | `waterfall*.jar`                              | waterfall ⭑ | 0.85   |
 * | `fabric[-_]loader*`, `fabric-server-launch*`  | fabric      | 0.80   |
 * | `quilt*`                                      | quilt       | 0.80   |
 * | `folia*`                                      | folia       | 0.80   |
 * | `pufferfish*`                                 | pufferfish  | 0.80   |
 * | `bungee(cord)*.jar`                           | bungeecord ⭑| 0.80   |
 * | `leaves*.jar`                                 | leaves      | 0.75   |
 * | `mohist*`                                     | mohist      | 0.75   |
 * | `sponge[forge|vanilla]-*.jar`                 | sponge      | 0.70   |
 * | `*paperclip*.jar`, `paper*.jar`               | paper       | 0.70   |
 * | `spigot-*`, `craftbukkit-*`                   | spigot      | 0.60   |
 * | `minecraft_server*.jar`                       | vanilla     | 0.50   |
 * | `server.jar`                                  | vanilla     | 0.45   |
 *
 * ⭑ proxy markers never win "software" while a game-side marker matched; they flip
 * `proxy => true`. A node running ONLY a proxy reports the proxy as the software,
 * `loader => 'velocity'`.
 *
 * Confidence = 0.15 (files seen) + winning weight + 0.10 when a directory proves the
 * loader family + 0.05 for minecraft-ish extras (`eula.txt`, `world*` dirs).
 */
final class DetectionService
{
    /** Always null in v1 — see class docblock. */
    private const JAVA_MAJOR_UNKNOWN = null;

    /** Cache TTL, minutes (build contract: key prefix `mctoolkit.*`). */
    private const CACHE_TTL_MINUTES = 15;

    /**
     * Content directory conventions shared by every egg layout; PATHS are stable
     * across loaders, only their EXISTENCE differs per loader family.
     */
    private const CONTENT_DIRS = ['plugins' => '/plugins', 'mods' => '/mods'];

    public function __construct(private readonly FileBridge $bridge) {}

    /**
     * @return array{
     *     software: string,
     *     loader: 'vanilla'|'bukkit'|'fabric'|'forge'|'velocity',
     *     version: ?string,
     *     java: ?int,
     *     detected_by: 'files',
     *     content_dirs: array{plugins: string, mods: string},
     *     proxy: bool,
     *     confidence: float,
     * }
     */
    public function detect(Server $server, bool $force = false): array
    {
        $key = 'mctoolkit.detect.' . $server->uuid;

        if (!$force) {
            $cached = cache()->get($key);

            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $rootEntries = $this->bridge->ls($server, '/');
        } catch (\Throwable) {
            // Node unreachable / rejected listing: report unknown WITHOUT caching, so the
            // next attempt retries instead of pinning the failure for the full TTL. Pages
            // map this shape onto the `minecraft-toolkit::banner.failures.gateway_down`
            // callout.
            return $this->result('unknown', 'vanilla', null, self::JAVA_MAJOR_UNKNOWN, false, 0.0);
        }

        $probes = $this->collectProbes($rootEntries);
        $match = $this->pickSoftware($probes, $server);

        $loader = $match['software_is_proxy']
            ? 'velocity'
            : $this->loaderOf($match['software']);

        $detected = $this->result(
            $match['software'],
            $loader,
            $match['version'],
            self::JAVA_MAJOR_UNKNOWN,
            $match['any_proxy'],
            $this->scoreConfidence($match, $probes),
        );

        cache()->put($key, $detected, now()->addMinutes(self::CACHE_TTL_MINUTES));

        return $detected;
    }

    /**
     * Whether installing PLUGIN/MOD content makes sense here: bukkity `plugins/`,
     * fabric-style `mods/`, forge-style `libraries/` proof, or a proxy's `plugins/`.
     * Velocity is included deliberately — Hangar-style catalogs serve proxies too.
     */
    public function supportsContent(Server $server): bool
    {
        $detected = $this->detect($server);

        // Directories are keyed off the loader family, not the exact software.
        if ($detected['loader'] === 'vanilla') {
            return false;
        }

        $candidates = match ($detected['loader']) {
            'bukkit', 'velocity' => ['/plugins'],
            'fabric' => ['/mods'],
            'forge' => ['/mods', '/libraries'],
            default => [],
        };

        foreach ($candidates as $dir) {
            if ($this->bridge->exists($server, $dir)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce one root listing into lowercase probe sets.
     *
     * @param  list<array<string, mixed>>  $entries  {@see FileBridge::ls()} output.
     * @return array{files: list<string>, dirs: list<string>, has_eula: bool, worlds: int}
     */
    private function collectProbes(array $entries): array
    {
        $files = [];
        $dirs = [];
        $worlds = 0;
        $hasEula = false;

        foreach ($entries as $entry) {
            $name = mb_strtolower(strval($entry['name'] ?? ''));

            if ($name === '') {
                continue;
            }

            if ($entry['directory'] ?? false) {
                $dirs[] = $name;

                if (str_starts_with($name, 'world')) {
                    $worlds++;
                }

                continue;
            }

            // Count regular files AND symlinks (eggs love symlinking their runnable);
            // anything else whose name still ends in .jar is kept defensively.
            if (($entry['symlink'] ?? false) || ($entry['file'] ?? false) || str_ends_with($name, '.jar')) {
                $files[] = $name;

                if ($name === 'eula.txt') {
                    $hasEula = true;
                }
            }
        }

        return ['files' => $files, 'dirs' => $dirs, 'has_eula' => $hasEula, 'worlds' => $worlds];
    }

    /**
     * Run markers over the probes, electing the most specific game-side software while
     * tracking whether any proxy marker fired at all.
     *
     * @param  array{files: list<string>, dirs: list<string>, has_eula: bool, worlds: int}  $probes
     * @return array{software: string, version: ?string, weight: float, software_is_proxy: bool, any_proxy: bool}
     */
    private function pickSoftware(array $probes, Server $server): array
    {
        $gameWinner = null;
        $proxyWinner = null;

        foreach ($this->markersFor($probes['files']) as [$software, $weight, $version]) {
            $isProxy = in_array($software, ['velocity', 'waterfall', 'bungeecord'], true);

            if ($isProxy) {
                if ($proxyWinner === null || $weight > $proxyWinner['weight']) {
                    $proxyWinner = ['software' => $software, 'version' => $version, 'weight' => $weight];
                }

                continue;
            }

            if ($gameWinner === null || $weight > $gameWinner['weight']) {
                $gameWinner = ['software' => $software, 'version' => $version, 'weight' => $weight];
            }
        }

        // NeoForge/Forge can hide behind a bare `libraries/net/<vendor>` tree when the
        // runnable itself was renamed away from "forge-*" conventions.
        if ($gameWinner === null && in_array('libraries', $probes['dirs'], true)) {
            foreach ($this->bridge->safeLs($server, '/libraries/net') as $lib) {
                $vendor = mb_strtolower(strval($lib['name'] ?? ''));

                if ($vendor === 'neoforged') {
                    $gameWinner = ['software' => 'neoforge', 'version' => null, 'weight' => 0.70];

                    break;
                }

                if ($vendor === 'minecraftforge') {
                    $gameWinner = ['software' => 'forge', 'version' => null, 'weight' => 0.70];

                    break;
                }
            }
        }

        $gameWinner ??= ['software' => 'unknown', 'version' => null, 'weight' => 0.0];

        // Pure-proxy box (Velocity-only nodes have no vanilla-style root artifacts):
        // promote the strongest proxy to headline software.
        if ($gameWinner['software'] === 'unknown' && $proxyWinner !== null) {
            $gameWinner = $proxyWinner;

            return [
                'software' => $gameWinner['software'],
                'version' => $gameWinner['version'],
                'weight' => $gameWinner['weight'],
                'software_is_proxy' => true,
                'any_proxy' => true,
            ];
        }

        return [
            'software' => $gameWinner['software'],
            'version' => $gameWinner['version'],
            'weight' => $gameWinner['weight'],
            'software_is_proxy' => false,
            'any_proxy' => $proxyWinner !== null,
        ];
    }

    /**
     * Match each candidate file against the marker table.
     *
     * @param  list<string>  $files
     * @return list<array{0: string, 1: float, 2: ?string}> [software, weight, version]
     */
    private function markersFor(array $files): array
    {
        $hits = [];

        foreach ($files as $name) {
            $hit = static function (string $software, float $weight) use (&$hits, $name): void {
                $hits[] = [$software, $weight, self::extractVersion($name)];
            };

            // --- proxies ------------------------------------------------------------
            if (preg_match('/^velocity[\w.-]*\.jar$/', $name) || str_contains($name, 'velocity')) {
                $hit('velocity', 0.90);

                continue;
            }

            if (preg_match('/^waterfall[\w.-]*\.jar$/', $name)) {
                $hit('waterfall', 0.85);

                continue;
            }

            if (preg_match('/^(bungeecord|bungee)[\w.-]*\.jar$/', $name)) {
                $hit('bungeecord', 0.80);

                continue;
            }

            if (!str_ends_with($name, '.jar')) {
                continue;
            }

            // --- modded loaders ------------------------------------------------------
            if (str_contains($name, 'neoforge')) {
                $hit('neoforge', 0.90);

                continue;
            }

            if (str_starts_with($name, 'forge-')) {
                $hit('forge', 0.85);

                continue;
            }

            // --- paper forks ---------------------------------------------------------
            if (str_contains($name, 'folia')) {
                $hit('folia', 0.80);

                continue;
            }

            if (str_contains($name, 'pufferfish')) {
                $hit('pufferfish', 0.80);

                continue;
            }

            if (str_starts_with($name, 'leaves') || preg_match('/[\W_-]leaves[\w.-]*\.jar$/', $name)) {
                $hit('leaves', 0.75);

                continue;
            }

            if (str_contains($name, 'mohist')) {
                $hit('mohist', 0.75);

                continue;
            }

            if (str_contains($name, 'purpur')) {
                $hit('purpur', 0.85);

                continue;
            }

            if (str_contains($name, 'spongeforge') || str_starts_with($name, 'spongevanilla')) {
                $hit('sponge', 0.70);

                continue;
            }

            // --- bukkit lineage -------------------------------------------------------
            if (str_contains($name, 'paperclip') || preg_match('/^paper[-_.\d]*/', $name)) {
                $hit('paper', 0.70);

                continue;
            }

            if (str_contains($name, 'spigot') || str_starts_with($name, 'craftbukkit')) {
                $hit('spigot', 0.60);

                continue;
            }

            // --- fabric lineage --------------------------------------------------------
            if (str_contains($name, 'fabric')) {
                $hit('fabric', 0.80);

                continue;
            }

            if (str_contains($name, 'quilt')) {
                $hit('quilt', 0.80);

                continue;
            }

            // --- vanilla fallbacks -----------------------------------------------------
            if ($name === 'server.jar' || preg_match('/^minecraft_server[\w.-]*\.jar$/', $name)) {
                $hit('vanilla', $name === 'server.jar' ? 0.45 : 0.50);

                continue;
            }
        }

        return $hits;
    }

    /** Map detected software id onto its content loader family. */
    private function loaderOf(string $software): string
    {
        return match ($software) {
            'paper', 'purpur', 'spigot', 'folia', 'pufferfish', 'leaves', 'mohist', 'sponge' => 'bukkit',
            'fabric', 'quilt' => 'fabric',
            'forge', 'neoforge' => 'forge',
            'velocity', 'waterfall', 'bungeecord' => 'velocity',
            default => 'vanilla',
        };
    }

    /**
     * Heuristic score: presence + specificity + corroborating directories/world files.
     *
     * @param  array{software: string, version: ?string, weight: float, software_is_proxy: bool, any_proxy: bool}  $match
     * @param  array{files: list<string>, dirs: list<string>, has_eula: bool, worlds: int}  $probes
     */
    private function scoreConfidence(array $match, array $probes): float
    {
        if ($match['software'] === 'unknown') {
            return 0.0;
        }

        $score = 0.15 + $match['weight'];

        $proofDirs = match ($this->loaderOf($match['software'])) {
            'bukkit', 'velocity' => ['plugins'],
            'fabric' => ['mods'],
            'forge' => ['mods', 'libraries'],
            default => [],
        };

        if ($proofDirs !== [] && !empty(array_intersect($proofDirs, $probes['dirs']))) {
            $score += 0.10;
        }

        if ($probes['has_eula'] || $probes['worlds'] > 0) {
            $score += 0.05;
        }

        return round(min($score, 1.0), 2);
    }

    /** First dotted release-looking token ("paper-1.21.3-257.jar" → "1.21.3"). */
    private static function extractVersion(string $name): ?string
    {
        if (preg_match('/(\d+\.\d+(?:\.\d+)*)/', $name, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Canonical result shape; identical keys ALWAYS present so pages never need
     * array_key_exists gymnastics.
     */
    private function result(string $software, string $loader, ?string $version, ?int $java, bool $proxy, float $confidence): array
    {
        return [
            'software' => $software,
            'loader' => $loader,
            'version' => $version,
            'java' => $java,
            'detected_by' => 'files',
            'content_dirs' => self::CONTENT_DIRS,
            'proxy' => $proxy,
            'confidence' => $confidence,
        ];
    }
}
