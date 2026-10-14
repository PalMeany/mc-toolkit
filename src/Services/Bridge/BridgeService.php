<?php

declare(strict_types=1);

namespace McToolkit\Services\Bridge;

use App\Models\Server;
use App\Models\User;
use DomainException;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Support\Activity;
use Throwable;

/**
 * The companion bridge: a server-side plugin/mod (sources in companion/, protocol in
 * companion/PROTOCOL.md) that writes live game data into `.toolkit/*.json`. This service
 * answers "is it there, is it running, is it current" and installs the copy bundled with
 * this panel plugin (resources/bridge/) with one click.
 *
 * Install target per detected software family:
 *  - Bukkit family (Paper, Spigot, Purpur, Folia, Pufferfish, hybrids…): `plugins/MCToolkitBridge.jar`
 *  - Fabric / Quilt / NeoForge / Forge (Minecraft 1.20.1+): `mods/mctoolkit-bridge.jar`
 *  - proxies, vanilla, Sponge and unknown software: not supported (nothing to hook into).
 *
 * The jar bytes travel through Wings' file write endpoint, which streams the request body
 * to disk unchanged — no download from the node is needed and the bridge version always
 * matches this panel plugin.
 */
final class BridgeService
{
    /** Files older than this are stale: the server stopped or the bridge is not loaded. */
    public const FRESH_SECONDS = 30;

    /** Bundled jars + manifest.json inside this plugin (config `bridge.bundle_dir` overrides). */
    private const BUNDLE_DIR = 'resources/bridge';

    /** Installed-jar file names per family and the patterns of older copies to replace. */
    private const TARGETS = [
        'bukkit' => ['dir' => 'plugins', 'file' => 'MCToolkitBridge.jar', 'pattern' => '/^mctoolkitbridge.*\.jar$/i', 'bundle' => 'bukkit'],
        'mod' => ['dir' => 'mods', 'file' => 'mctoolkit-bridge.jar', 'pattern' => '/^mctoolkit[-_]bridge.*\.jar$/i', 'bundle' => 'mod'],
    ];

    /** Bukkit-family software that does NOT load Bukkit plugins despite the detection family. */
    private const NOT_BUKKIT = ['sponge'];

    public function __construct(
        private readonly FileBridge $files,
        private readonly DetectionService $detection,
    ) {}

    /**
     * Everything the pages need to render the bridge state.
     *
     * @return array{
     *     supported: bool, family: ?string, target: ?string, reason: ?string,
     *     installed: bool, running: bool, stale: bool,
     *     version: ?string, platform: ?string, updated_at: ?int,
     *     bundled_version: ?string, update_available: bool,
     * }
     */
    public function status(Server $server, bool $force = false): array
    {
        $key = 'mc-toolkit.bridge.status.'.$server->getKey();

        if ($force) {
            cache()->forget($key);
        }

        return cache()->remember($key, 10, fn (): array => $this->computeStatus($server));
    }

    public function forget(Server $server): void
    {
        cache()->forget('mc-toolkit.bridge.status.'.$server->getKey());
    }

    /**
     * Whether a decoded bridge file is current. Files without the protocol's meta block
     * (hand-written or third-party writers) count as current, as they always did.
     *
     * @param  array<mixed>  $payload
     */
    public static function isFresh(array $payload): bool
    {
        $meta = $payload['bridge'] ?? null;

        if (! is_array($meta) || ! is_numeric($meta['updated_at'] ?? null)) {
            return true;
        }

        if (($meta['running'] ?? true) === false) {
            return false;
        }

        return abs(time() - (int) $meta['updated_at']) <= self::FRESH_SECONDS;
    }

    /** Version of the bridge bundled with this panel plugin, null when the bundle is missing. */
    public function bundledVersion(): ?string
    {
        $manifest = $this->manifest();

        return is_string($manifest['version'] ?? null) ? $manifest['version'] : null;
    }

    /**
     * Write the bundled bridge jar into the server's plugins/ or mods/ folder, replacing any
     * older copy. Takes effect on the next server start.
     *
     * @return array{path:string, version:?string}
     *
     * @throws DomainException translated, when unsupported or the node refuses
     */
    public function install(Server $server, ?User $actor = null): array
    {
        if ($server->isInConflictState()) {
            throw new DomainException(trans('minecraft-toolkit::errors.statuses.server_unavailable'));
        }

        $target = $this->target($server);

        if ($target['family'] === null) {
            throw new DomainException(trans('minecraft-toolkit::pages.bridge.unsupported', ['software' => $target['software']]));
        }

        $spec = self::TARGETS[$target['family']];
        $bundle = $this->bundleFile($spec['bundle']);

        if ($bundle === null) {
            throw new DomainException(trans('minecraft-toolkit::pages.bridge.bundle_missing'));
        }

        $dir = '/'.trim($target['dir'], '/');

        try {
            $doomed = [];

            foreach ($this->safeLs($server, $dir) as $entry) {
                if (! $entry['directory'] && preg_match($spec['pattern'], $entry['name']) === 1) {
                    $doomed[] = $entry['name'];
                }
            }

            if ($doomed !== []) {
                $this->files->delete($server, $doomed, $dir);
            }

            $path = $dir.'/'.$spec['file'];
            $this->files->write($server, $path, $bundle);
        } catch (Throwable $e) {
            report($e);

            throw new DomainException(trans('minecraft-toolkit::errors.statuses.wings_502'), 0, $e);
        }

        $this->forget($server);

        try {
            $log = Activity::event('mc-toolkit:bridge.install')
                ->subject($server)
                ->property('version', $this->bundledVersion() ?? '?')
                ->property('file', ltrim($path, '/'));

            if ($actor !== null) {
                $log->actor($actor);
            }

            $log->log();
        } catch (Throwable) {
            // logging never undoes an install
        }

        return ['path' => ltrim($path, '/'), 'version' => $this->bundledVersion()];
    }

    // ------------------------------------------------------------------ internals

    private function computeStatus(Server $server): array
    {
        $target = $this->target($server);
        $bundled = $this->bundledVersion();

        $status = [
            'supported' => $target['family'] !== null,
            'family' => $target['family'],
            'target' => $target['family'] === null ? null : ltrim($target['dir'], '/').'/'.self::TARGETS[$target['family']]['file'],
            'reason' => $target['family'] === null ? $target['software'] : null,
            'installed' => false,
            'running' => false,
            'stale' => false,
            'version' => null,
            'platform' => null,
            'updated_at' => null,
            'bundled_version' => $bundled,
            'update_available' => false,
        ];

        // Installed jar (any family: a server may have switched software since).
        foreach (self::TARGETS as $family => $spec) {
            $dir = $family === $target['family'] ? $target['dir'] : '/'.$spec['dir'];

            foreach ($this->safeLs($server, $dir) as $entry) {
                if (! $entry['directory'] && preg_match($spec['pattern'], $entry['name']) === 1) {
                    $status['installed'] = true;
                }
            }
        }

        // Live state: the light state.json carries the meta block.
        try {
            $state = $this->files->readJson($server, '/'.self::dir().'/state.json');
        } catch (Throwable) {
            $state = null;
        }

        if (is_array($state)) {
            $meta = is_array($state['bridge'] ?? null) ? $state['bridge'] : [];
            $status['version'] = is_string($meta['version'] ?? null) ? $meta['version'] : null;
            $status['platform'] = is_string($meta['platform'] ?? null) ? $meta['platform'] : null;
            $status['updated_at'] = is_numeric($meta['updated_at'] ?? null) ? (int) $meta['updated_at'] : null;
            $status['running'] = self::isFresh($state);
            $status['stale'] = ! $status['running'];
            // A writer without the meta block still proves something is installed.
            $status['installed'] = $status['installed'] || $status['running'];
        }

        if ($bundled !== null && $status['version'] !== null && $status['installed']) {
            $status['update_available'] = version_compare($bundled, $status['version'], '>');
        }

        return $status;
    }

    /**
     * Bridge family + folder for the detected software.
     *
     * @return array{family:?string, dir:string, software:string}
     */
    private function target(Server $server): array
    {
        try {
            $detected = $this->detection->detect($server);
        } catch (Throwable) {
            $detected = [];
        }

        $software = strtolower((string) ($detected['software'] ?? 'unknown'));
        $loader = strtolower((string) ($detected['loader'] ?? 'vanilla'));
        $dirs = is_array($detected['content_dirs'] ?? null) ? $detected['content_dirs'] : [];

        if (($detected['proxy'] ?? false) === true || $loader === 'velocity' || in_array($software, self::NOT_BUKKIT, true)) {
            return ['family' => null, 'dir' => '', 'software' => ucfirst($software)];
        }

        if ($loader === 'bukkit') {
            return ['family' => 'bukkit', 'dir' => (string) ($dirs['plugins'] ?? '/plugins'), 'software' => ucfirst($software)];
        }

        if (in_array($loader, ['fabric', 'forge'], true) && $software !== 'legacy_fabric') {
            return ['family' => 'mod', 'dir' => (string) ($dirs['mods'] ?? '/mods'), 'software' => ucfirst($software)];
        }

        return ['family' => null, 'dir' => '', 'software' => ucfirst($software === 'unknown' ? 'unknown' : $software)];
    }

    /** @return list<array{name:string, directory:bool}> */
    private function safeLs(Server $server, string $dir): array
    {
        try {
            return array_map(
                fn (array $row): array => ['name' => (string) $row['name'], 'directory' => (bool) $row['directory']],
                $this->files->ls($server, $dir),
            );
        } catch (Throwable) {
            return [];
        }
    }

    /** Absolute folder holding the bundled jars and manifest.json. */
    private function bundleDir(): string
    {
        $override = config('minecraft-toolkit.bridge.bundle_dir');

        return is_string($override) && $override !== ''
            ? rtrim($override, '/')
            : plugin_path('minecraft-toolkit', self::BUNDLE_DIR);
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        static $cache = [];

        $path = $this->bundleDir().'/manifest.json';

        if (! array_key_exists($path, $cache)) {
            $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
            $cache[$path] = is_array($decoded) ? $decoded : [];
        }

        return $cache[$path];
    }

    private function bundleFile(string $bundle): ?string
    {
        $entry = $this->manifest()['files'][$bundle] ?? null;
        $file = is_array($entry) && is_string($entry['file'] ?? null) ? basename($entry['file']) : null;

        if ($file === null) {
            return null;
        }

        $path = $this->bundleDir().'/'.$file;

        if (! is_file($path)) {
            return null;
        }

        $bytes = (string) file_get_contents($path);
        $sha = is_string($entry['sha256'] ?? null) ? strtolower($entry['sha256']) : null;

        // A truncated or swapped bundle must never land in a server's plugins folder.
        return $sha === null || hash_equals($sha, hash('sha256', $bytes)) ? $bytes : null;
    }

    public static function dir(): string
    {
        $dir = trim((string) config('minecraft-toolkit.bridge.path', '.toolkit'), '/ ');

        return $dir === '' ? '.toolkit' : $dir;
    }
}
