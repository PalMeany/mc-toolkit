<?php

declare(strict_types=1);

namespace McToolkit\Services;

use App\Models\Server;

/**
 * Figures out WHAT is installed on a server: vanilla / Paper fork / Spigot / Fabric /
 * Quilt / Forge / NeoForge / mohist / sponge / a Velocity-Waterfall-BungeeCord proxy,
 * plus the content folders each flavor uses (parity item from the original addon's
 * "Paper 1.21.3 · Detected from the files" banner).
 *
 * Honesty note (v1 scope): the java major version is NOT resolved — it needs either
 * runtime log parsing or reading the docker image tag, both out of v1. `java` stays null.
 * Jars are never unzipped.
 *
 * Evidence, strongest first (highest weight wins; proxies are tracked separately):
 *
 * | evidence                                                   | software        | weight |
 * |------------------------------------------------------------|-----------------|--------|
 * | `.mctoolkit-install.json` written by the Versions page,    | as recorded     | wins   |
 * |   still matching the jar sizes on disk                     |                 |        |
 * | jar names: `velocity*`, `neoforge*`, `forge-*`, `purpur*`, | per name        | .5–.9  |
 * |   `folia*`, `paper*`, `fabric*`, `minecraft_server*`, …    |                 |        |
 * | `velocity.toml` / `waterfall.yml` / `modules.yml`          | proxy           | .7–.85 |
 * | `purpur.yml` / `pufferfish.yml` / `leaves.yml`             | that fork       | .76–.8 |
 * | `.fabric/` / `.quilt/` / `mohist-config/`                  | that loader     | 0.74   |
 * | `libraries/net/{neoforged/neoforge, minecraftforge/forge,  | that loader     | .70–.72|
 * |   fabricmc/fabric-loader}`                                 |                 |        |
 * | `config/paper-global.yml`, root `paper.yml`                | paper           | .66–.68|
 * | `spigot.yml` / `bukkit.yml`                                | spigot          | .5–.52 |
 * | egg tag naming a known software                            | that software   | 0.42   |
 * | `server.jar` or the egg's SERVER_JARFILE (generic runnable)| vanilla         | 0.40   |
 *
 * Standard eggs (and MCJars' own install steps) name the runnable `server.jar`, so the
 * file NAME alone says nothing about the software — that is why config files, library
 * trees and egg hints are consulted before falling back to vanilla.
 *
 * `version` is always the MINECRAFT version for game software (NeoForge's loader
 * version 21.1.77 maps to 1.21.1; Forge's library folder 1.20.1-47.2.0 to 1.20.1) and the
 * project version for proxies. When no file carries it, the vanilla bundler's
 * `versions/<mc>/` folder and finally the egg's MINECRAFT_VERSION / MC_VERSION are used.
 *
 * Confidence = 0.15 (files seen) + winning weight + 0.10 when a directory proves the
 * loader family + 0.05 for minecraft-ish extras (`eula.txt`, `world*` dirs).
 */
final class DetectionService
{
    /** Always null in v1 — see class docblock. */
    private const JAVA_MAJOR_UNKNOWN = null;

    /** Fallback cache TTL (seconds) when config('minecraft-toolkit.cache_ttl.detect') is unset. */
    private const CACHE_TTL_SECONDS = 900;

    /**
     * Written by the Versions page after a successful install: records what was installed
     * and the size of every file it placed, so a later manual swap invalidates it.
     */
    public const INSTALL_MARKER = '.mctoolkit-install.json';

    /** Bytes read at most from the install marker. */
    private const INSTALL_MARKER_MAX_BYTES = 16384;

    /**
     * Content directory conventions shared by every egg layout; PATHS are stable
     * across loaders, only their EXISTENCE differs per loader family.
     */
    private const CONTENT_DIRS = ['plugins' => '/plugins', 'mods' => '/mods'];

    private const PROXIES = ['velocity', 'velocity_ctd', 'waterfall', 'bungeecord'];

    /** Root config files only one server family writes: name => [software, weight]. */
    private const MARKER_FILES = [
        'velocity.toml' => ['velocity', 0.85],
        'waterfall.yml' => ['waterfall', 0.80],
        'modules.yml' => ['bungeecord', 0.70],
        'purpur.yml' => ['purpur', 0.80],
        'pufferfish.yml' => ['pufferfish', 0.78],
        'leaves.yml' => ['leaves', 0.76],
        'paper.yml' => ['paper', 0.66],
        'spigot.yml' => ['spigot', 0.52],
        'bukkit.yml' => ['spigot', 0.50],
    ];

    /** Root directories only one loader creates: name => [software, weight]. */
    private const MARKER_DIRS = [
        '.fabric' => ['fabric', 0.74],
        '.quilt' => ['quilt', 0.74],
        'mohist-config' => ['mohist', 0.74],
    ];

    /** Below every specific marker: only decides when nothing else matched. */
    private const GENERIC_RUNNABLE_WEIGHT = 0.40;

    private const EGG_TAG_WEIGHT = 0.42;

    /** Probing config/ and libraries/ is skipped once evidence at least this strong exists. */
    private const STRONG_WEIGHT = 0.66;

    /** Egg tags that name a software outright. */
    private const KNOWN_SOFTWARE = [
        'vanilla', 'paper', 'purpur', 'spigot', 'bukkit', 'folia', 'pufferfish', 'leaves',
        'mohist', 'sponge', 'fabric', 'quilt', 'forge', 'neoforge', 'velocity', 'waterfall',
        'bungeecord',
    ];

    public function __construct(private readonly FileBridge $bridge) {}

    /**
     * @return array{
     *     software: string,
     *     loader: 'vanilla'|'bukkit'|'fabric'|'forge'|'velocity',
     *     version: ?string,
     *     java: ?int,
     *     detected_by: 'files'|'toolkit',
     *     content_dirs: array{plugins: string, mods: string},
     *     proxy: bool,
     *     confidence: float,
     *     reachable: bool,
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
            // next attempt retries instead of pinning the failure for the full TTL. The
            // `reachable => false` flag lets pages show the gateway_down copy instead of
            // "not a Minecraft server".
            return $this->result('unknown', 'vanilla', null, false, 0.0, reachable: false);
        }

        $probes = $this->collectProbes($rootEntries);

        $detected = $this->fromInstallMarker($server, $probes);

        if ($detected === null) {
            $egg = $this->eggHints($server);
            $match = $this->pickSoftware($probes, $server, $egg);

            $version = $match['version'];

            if ($version === null && !$match['software_is_proxy'] && $match['software'] !== 'unknown') {
                $version = $this->bundlerVersion($server, $probes) ?? $egg['mc_version'];
            }

            $detected = $this->result(
                $match['software'],
                $match['software_is_proxy'] ? 'velocity' : $this->loaderOf($match['software']),
                $version,
                $match['any_proxy'],
                $this->scoreConfidence($match, $probes),
            );
        }

        $ttl = (int) config('minecraft-toolkit.cache_ttl.detect', self::CACHE_TTL_SECONDS);

        cache()->put($key, $detected, now()->addSeconds(max(60, $ttl)));

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
     * Whether a root-level file name looks like a server-software runnable (any known
     * software jar name, `server.jar`, or `minecraft_server*.jar`). The Versions page uses
     * it to sweep the previous software's jars without touching unrelated ones.
     */
    public function isServerSoftwareJar(string $fileName): bool
    {
        $name = mb_strtolower(basename(str_replace('\\', '/', $fileName)));

        return str_ends_with($name, '.jar')
            && ($name === 'server.jar' || self::markersFor([$name]) !== []);
    }

    /**
     * Map an MCJars type id (PAPER, NEOFORGE, VELOCITY_CTD, …) onto the software id the
     * detection result uses, so "Installed" chips compare like for like.
     */
    public static function softwareFromCatalogType(string $type): string
    {
        $id = mb_strtolower(trim($type));

        return preg_match('/^[a-z0-9_]{1,40}$/', $id) === 1 ? $id : 'unknown';
    }

    /**
     * Reduce one root listing into lowercase probe sets.
     *
     * @param  list<array<string, mixed>>  $entries  {@see FileBridge::ls()} output.
     * @return array{files: list<string>, dirs: list<string>, sizes: array<string, int>, has_eula: bool, worlds: int}
     */
    private function collectProbes(array $entries): array
    {
        $files = [];
        $dirs = [];
        $sizes = [];
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
                $sizes[$name] = intval($entry['size'] ?? 0);

                if ($name === 'eula.txt') {
                    $hasEula = true;
                }
            }
        }

        return ['files' => $files, 'dirs' => $dirs, 'sizes' => $sizes, 'has_eula' => $hasEula, 'worlds' => $worlds];
    }

    /**
     * Trust the Versions page's own record while every file it placed in the root is
     * still there with the size it had right after the install.
     *
     * @param  array{files: list<string>, dirs: list<string>, sizes: array<string, int>, has_eula: bool, worlds: int}  $probes
     */
    private function fromInstallMarker(Server $server, array $probes): ?array
    {
        if (!in_array(self::INSTALL_MARKER, $probes['files'], true)) {
            return null;
        }

        try {
            $raw = $this->bridge->read($server, self::INSTALL_MARKER, self::INSTALL_MARKER_MAX_BYTES);
            $marker = $raw === null ? null : json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($marker) || !is_string($marker['software'] ?? null) || !is_array($marker['files'] ?? null)) {
            return null;
        }

        $software = self::softwareFromCatalogType($marker['software']);

        if ($software === 'unknown' || $marker['files'] === []) {
            return null;
        }

        foreach ($marker['files'] as $file => $size) {
            $name = mb_strtolower((string) $file);

            // Only root-level files are verifiable from the one listing we already have.
            if (str_contains($name, '/')) {
                continue;
            }

            if (!array_key_exists($name, $probes['sizes']) || (is_int($size) && $probes['sizes'][$name] !== $size)) {
                return null;
            }
        }

        $version = is_string($marker['version'] ?? null) && trim($marker['version']) !== ''
            ? trim($marker['version'])
            : null;

        $isProxy = in_array($software, self::PROXIES, true);

        return $this->result(
            $software,
            $isProxy ? 'velocity' : $this->loaderOf($software),
            $version,
            $isProxy,
            round(min(1.0, 0.95 + ($probes['has_eula'] || $probes['worlds'] > 0 ? 0.05 : 0.0)), 2),
            detectedBy: 'toolkit',
        );
    }

    /**
     * Egg-side hints: software-naming tags, the SERVER_JARFILE name (a generic runnable)
     * and the MINECRAFT_VERSION / MC_VERSION value. Never throws.
     *
     * @return array{tags: list<string>, jarfile: ?string, mc_version: ?string}
     */
    private function eggHints(Server $server): array
    {
        $hints = ['tags' => [], 'jarfile' => null, 'mc_version' => null];

        try {
            $server->loadMissing('egg');

            $tags = $server->egg?->tags;

            foreach (is_array($tags) ? $tags : [] as $tag) {
                if (is_string($tag) && in_array($normalized = mb_strtolower(trim($tag)), self::KNOWN_SOFTWARE, true)) {
                    $hints['tags'][] = $normalized;
                }
            }

            $variables = $server->variables()
                ->whereIn('env_variable', ['SERVER_JARFILE', 'MINECRAFT_VERSION', 'MC_VERSION'])
                ->get();

            foreach ($variables as $variable) {
                $value = trim((string) ($variable->server_value ?? $variable->default_value ?? ''));

                if ($value === '') {
                    continue;
                }

                if ($variable->env_variable === 'SERVER_JARFILE') {
                    $jar = mb_strtolower($value);

                    if (!str_contains($jar, '/') && str_ends_with($jar, '.jar')) {
                        $hints['jarfile'] = $jar;
                    }

                    continue;
                }

                if ($hints['mc_version'] === null && ($mc = self::extractVersion(mb_strtolower($value), game: true)) !== null) {
                    $hints['mc_version'] = $mc;
                }
            }
        } catch (\Throwable) {
            // Hints are optional; a broken relation must never break detection.
        }

        return $hints;
    }

    /**
     * Run every marker over the probes, electing the strongest game-side software while
     * tracking whether any proxy marker fired at all.
     *
     * @param  array{files: list<string>, dirs: list<string>, sizes: array<string, int>, has_eula: bool, worlds: int}  $probes
     * @param  array{tags: list<string>, jarfile: ?string, mc_version: ?string}  $egg
     * @return array{software: string, version: ?string, weight: float, software_is_proxy: bool, any_proxy: bool}
     */
    private function pickSoftware(array $probes, Server $server, array $egg): array
    {
        /** @var list<array{0: string, 1: float, 2: ?string}> $hits */
        $hits = self::markersFor($probes['files']);

        foreach (self::MARKER_FILES as $file => [$software, $weight]) {
            if (in_array($file, $probes['files'], true)) {
                $hits[] = [$software, $weight, null];
            }
        }

        foreach (self::MARKER_DIRS as $dir => [$software, $weight]) {
            if (in_array($dir, $probes['dirs'], true)) {
                $hits[] = [$software, $weight, null];
            }
        }

        foreach ($egg['tags'] as $tag) {
            $hits[] = [$tag === 'bukkit' ? 'spigot' : $tag, self::EGG_TAG_WEIGHT, null];
        }

        // Generic runnable: says "some Minecraft server lives here", nothing more.
        foreach (array_unique(array_filter(['server.jar', $egg['jarfile']])) as $runnable) {
            if (in_array($runnable, $probes['files'], true)) {
                $hits[] = ['vanilla', self::GENERIC_RUNNABLE_WEIGHT, self::extractVersion($runnable, game: true)];
            }
        }

        // Deeper probes only when nothing specific has spoken yet — Paper also ships a
        // libraries/ tree, and every modded server has a config/ folder.
        if ($this->strongestGameWeight($hits) < self::STRONG_WEIGHT) {
            if (in_array('config', $probes['dirs'], true)) {
                foreach ($this->bridge->safeLs($server, '/config') as $entry) {
                    if (mb_strtolower(strval($entry['name'] ?? '')) === 'paper-global.yml') {
                        $hits[] = ['paper', 0.68, null];

                        break;
                    }
                }
            }

            if (in_array('libraries', $probes['dirs'], true)) {
                array_push($hits, ...$this->libraryMarkers($server));
            }
        }

        $gameWinner = null;
        $proxyWinner = null;

        foreach ($hits as [$software, $weight, $version]) {
            if (in_array($software, self::PROXIES, true)) {
                if ($proxyWinner === null || $weight > $proxyWinner['weight']
                    || ($weight === $proxyWinner['weight'] && $proxyWinner['version'] === null && $version !== null)) {
                    $proxyWinner = ['software' => $software, 'version' => $version, 'weight' => $weight];
                }

                continue;
            }

            if ($gameWinner === null || $weight > $gameWinner['weight']
                || ($weight === $gameWinner['weight'] && $gameWinner['version'] === null && $version !== null)) {
                $gameWinner = ['software' => $software, 'version' => $version, 'weight' => $weight];
            }
        }

        // The weakest evidence (generic runnable) may still know a version through a
        // weaker-but-versioned sibling, e.g. purpur.yml (no version) + purpur-1.21.4.jar.
        if ($gameWinner !== null && $gameWinner['version'] === null) {
            foreach ($hits as [$software, $weight, $version]) {
                if ($software === $gameWinner['software'] && $version !== null) {
                    $gameWinner['version'] = $version;

                    break;
                }
            }
        }

        // Pure-proxy box (Velocity-only nodes have no vanilla-style root artifacts):
        // promote the strongest proxy to headline software. A bare `server.jar` next to
        // velocity.toml is the proxy's own runnable, not a game server.
        if ($proxyWinner !== null && ($gameWinner === null || $gameWinner['weight'] <= self::GENERIC_RUNNABLE_WEIGHT)) {
            return [
                'software' => $proxyWinner['software'],
                'version' => $proxyWinner['version'],
                'weight' => $proxyWinner['weight'],
                'software_is_proxy' => true,
                'any_proxy' => true,
            ];
        }

        $gameWinner ??= ['software' => 'unknown', 'version' => null, 'weight' => 0.0];

        return [
            'software' => $gameWinner['software'],
            'version' => $gameWinner['version'],
            'weight' => $gameWinner['weight'],
            'software_is_proxy' => false,
            'any_proxy' => $proxyWinner !== null,
        ];
    }

    /**
     * @param  list<array{0: string, 1: float, 2: ?string}>  $hits
     */
    private function strongestGameWeight(array $hits): float
    {
        $best = 0.0;

        foreach ($hits as [$software, $weight]) {
            if (!in_array($software, self::PROXIES, true) && $weight > $best) {
                $best = $weight;
            }
        }

        return $best;
    }

    /**
     * Forge / NeoForge / Fabric leave their loader artifact under libraries/net/<vendor>/;
     * the artifact's version folder also yields the Minecraft version.
     *
     * @return list<array{0: string, 1: float, 2: ?string}>
     */
    private function libraryMarkers(Server $server): array
    {
        $vendors = array_map(
            static fn (array $entry): string => mb_strtolower(strval($entry['name'] ?? '')),
            $this->bridge->safeLs($server, '/libraries/net'),
        );

        $hits = [];

        if (in_array('neoforged', $vendors, true)) {
            $versions = $this->childNames($server, '/libraries/net/neoforged/neoforge');

            if ($versions !== null) {
                $hits[] = ['neoforge', 0.72, self::latestMappedVersion($versions, self::minecraftFromNeoForge(...))];
            }
        }

        if (in_array('minecraftforge', $vendors, true)) {
            $versions = $this->childNames($server, '/libraries/net/minecraftforge/forge');

            if ($versions !== null) {
                // Folder names look like "1.20.1-47.2.0": the part before the dash is MC.
                $hits[] = ['forge', 0.70, self::latestMappedVersion(
                    $versions,
                    static fn (string $name): ?string => self::extractVersion(strstr($name, '-', true) ?: $name, game: true),
                )];
            }
        }

        if (in_array('fabricmc', $vendors, true)) {
            if ($this->childNames($server, '/libraries/net/fabricmc/fabric-loader') !== null) {
                $hits[] = ['fabric', 0.70, null];
            }
        }

        return $hits;
    }

    /**
     * Names inside a directory, or null when it does not exist / cannot be listed.
     *
     * @return list<string>|null
     */
    private function childNames(Server $server, string $dir): ?array
    {
        try {
            $rows = $this->bridge->ls($server, $dir);
        } catch (\Throwable) {
            return null;
        }

        return array_values(array_filter(array_map(
            static fn (array $row): string => strval($row['name'] ?? ''),
            $rows,
        ), static fn (string $name): bool => $name !== ''));
    }

    /**
     * Highest mapped version among folder names (several builds may linger after updates).
     *
     * @param  list<string>  $names
     * @param  callable(string): ?string  $map
     */
    private static function latestMappedVersion(array $names, callable $map): ?string
    {
        $best = null;

        foreach ($names as $name) {
            $mapped = $map(mb_strtolower($name));

            if ($mapped !== null && ($best === null || version_compare($mapped, $best, '>'))) {
                $best = $mapped;
            }
        }

        return $best;
    }

    /**
     * The 1.18+ vanilla bundler (and Paperclip) unpack the real server into
     * versions/<mc>/; the most recently modified folder is the version that ran last.
     *
     * @param  array{files: list<string>, dirs: list<string>, sizes: array<string, int>, has_eula: bool, worlds: int}  $probes
     */
    private function bundlerVersion(Server $server, array $probes): ?string
    {
        if (!in_array('versions', $probes['dirs'], true)) {
            return null;
        }

        $best = null;
        $bestTime = -1;

        foreach ($this->bridge->safeLs($server, '/versions') as $entry) {
            if (!($entry['directory'] ?? false)) {
                continue;
            }

            $version = self::extractVersion(mb_strtolower(strval($entry['name'] ?? '')), game: true);

            if ($version === null) {
                continue;
            }

            $time = strtotime(strval($entry['modified'] ?? '')) ?: 0;

            if ($time > $bestTime) {
                [$best, $bestTime] = [$version, $time];
            }
        }

        return $best;
    }

    /**
     * Match each candidate jar name against the name markers. `server.jar` is NOT a
     * marker: it is the generic runnable every standard egg uses (see pickSoftware()).
     *
     * @param  list<string>  $files  lowercase names
     * @return list<array{0: string, 1: float, 2: ?string}> [software, weight, version]
     */
    private static function markersFor(array $files): array
    {
        $hits = [];

        foreach ($files as $name) {
            if (!str_ends_with($name, '.jar') || $name === 'server.jar') {
                continue;
            }

            $hit = static function (string $software, float $weight) use (&$hits, $name): void {
                $isProxy = in_array($software, self::PROXIES, true);
                $version = $software === 'neoforge'
                    ? self::minecraftFromNeoForge($name)
                    : self::extractVersion($name, game: !$isProxy);

                $hits[] = [$software, $weight, $version];
            };

            // --- proxies ------------------------------------------------------------
            if (str_contains($name, 'velocity')) {
                $hit('velocity', 0.90);

                continue;
            }

            if (str_starts_with($name, 'waterfall')) {
                $hit('waterfall', 0.85);

                continue;
            }

            if (preg_match('/^(bungeecord|bungee)[\w.-]*\.jar$/', $name)) {
                $hit('bungeecord', 0.80);

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
            if (str_contains($name, 'paperclip') || str_starts_with($name, 'paper')) {
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

            // --- vanilla by name -------------------------------------------------------
            if (preg_match('/^minecraft_server[\w.-]*\.jar$/', $name)) {
                $hit('vanilla', 0.50);
            }
        }

        return $hits;
    }

    /** Map detected software id onto its content loader family. */
    private function loaderOf(string $software): string
    {
        return match ($software) {
            'paper', 'purpur', 'spigot', 'folia', 'pufferfish', 'leaves', 'mohist', 'sponge',
            'arclight', 'magma', 'canvas', 'divinemc', 'leaf', 'aspaper' => 'bukkit',
            'fabric', 'quilt', 'legacy_fabric' => 'fabric',
            'forge', 'neoforge' => 'forge',
            'velocity', 'velocity_ctd', 'waterfall', 'bungeecord' => 'velocity',
            default => 'vanilla',
        };
    }

    /**
     * Heuristic score: presence + specificity + corroborating directories/world files.
     *
     * @param  array{software: string, version: ?string, weight: float, software_is_proxy: bool, any_proxy: bool}  $match
     * @param  array{files: list<string>, dirs: list<string>, sizes: array<string, int>, has_eula: bool, worlds: int}  $probes
     */
    private function scoreConfidence(array $match, array $probes): float
    {
        if ($match['software'] === 'unknown') {
            return 0.0;
        }

        $score = 0.15 + $match['weight'];

        $proofDirs = match ($match['software_is_proxy'] ? 'velocity' : $this->loaderOf($match['software'])) {
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

    /**
     * First release-looking token of a (lowercase) name.
     *
     * For game software ($game = true) the token may carry a pre-release / release
     * candidate / snapshot suffix ("1.21.5-pre1", "26.1-snapshot-2") so such builds are
     * never mistaken for the final release; weekly snapshots ("24w14a") are recognised
     * too. Proxies keep the plain dotted token ("velocity-3.4.0-snapshot-500" → "3.4.0").
     */
    private static function extractVersion(string $name, bool $game = false): ?string
    {
        if ($game) {
            // Not glued to further digits on either side ("minecraft_server.1.21.4.jar" → 1.21.4).
            if (preg_match('/(?<!\d)(?<!\d\.)(\d+\.\d+(?:\.\d+)?(?:-(?:pre|rc)-?\d+|-snapshot-\d+)?)(?!\d)(?!\.\d)/', $name, $matches) === 1) {
                return $matches[1];
            }

            if (preg_match('/(?<![a-z\d])(\d{2}w\d{2}[a-z])(?![a-z\d])/', $name, $matches) === 1) {
                return $matches[1];
            }

            return null;
        }

        if (preg_match('/(\d+\.\d+(?:\.\d+)*)/', $name, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * NeoForge numbers its loader after the Minecraft version it targets:
     * 21.1.77 → 1.21.1, 21.0.5 → 1.21, 20.4.237 → 1.20.4; from the 26.x year-based
     * scheme on the leading numbers already are the Minecraft version (26.1.0.3 → 26.1).
     */
    private static function minecraftFromNeoForge(string $name): ?string
    {
        if (preg_match('/(\d+)\.(\d+)(?:\.(\d+))?(?:\.(\d+))?/', $name, $m) !== 1) {
            return null;
        }

        $major = (int) $m[1];
        $minor = (int) $m[2];

        if ($major >= 26) {
            $patch = isset($m[4]) && $m[4] !== '' && isset($m[3]) && (int) $m[3] > 0 ? '.' . (int) $m[3] : '';

            return $major . '.' . $minor . $patch;
        }

        if ($major < 20) {
            return null;
        }

        return '1.' . $major . ($minor > 0 ? '.' . $minor : '');
    }

    /**
     * Canonical result shape; identical keys ALWAYS present so pages never need
     * array_key_exists gymnastics.
     */
    private function result(
        string $software,
        string $loader,
        ?string $version,
        bool $proxy,
        float $confidence,
        bool $reachable = true,
        string $detectedBy = 'files',
    ): array {
        return [
            'software' => $software,
            'loader' => $loader,
            'version' => $version,
            'java' => self::JAVA_MAJOR_UNKNOWN,
            'detected_by' => $detectedBy,
            'content_dirs' => self::CONTENT_DIRS,
            'proxy' => $proxy,
            'confidence' => $confidence,
            'reachable' => $reachable,
        ];
    }
}
