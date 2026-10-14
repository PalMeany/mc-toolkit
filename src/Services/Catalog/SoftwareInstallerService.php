<?php

declare(strict_types=1);

namespace McToolkit\Services\Catalog;

use App\Enums\ContainerStatus;
use App\Enums\SubuserPermission;
use App\Facades\Activity;
use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use Closure;
use Illuminate\Http\Client\RequestException;
use McToolkit\Services\DetectionService;
use McToolkit\Services\FileBridge;
use McToolkit\Support\TaskSuperseded;
use Throwable;

/**
 * Installs or changes the server software from an MCJars build — the engine behind the
 * Versions page and the modpack loader install. Works without a Livewire or auth
 * context: the server and the acting user are passed in, progress is reported through
 * a callback, adjustments come back as notes. Callers own permission checks for the
 * install itself; the Docker image and startup command are only changed when the acting
 * user holds the matching Startup permission (anything else becomes a note).
 *
 * Install flow — nothing on the server changes until every file is downloaded:
 *   1. validate the MCJars `installation` steps (download / unzip / remove, safe paths)
 *   2. pull every download in the FOREGROUND under its exact target name into a staging
 *      folder while the server keeps running (MCJars' `server.jar` is written under the
 *      egg's SERVER_JARFILE so the startup command keeps working)
 *   3. stop the server and wait until wings really reports it offline
 *   4. optional archive of the old jars + world folders (server offline = consistent)
 *   5. remove the previous software's runnable jars and a root `unix_args.txt`, then
 *      replay the steps in order: staged files are moved into place, archives unzipped
 *      into their `location`, `remove` steps applied; an archive build's root
 *      `server.jar` launcher is renamed to SERVER_JARFILE
 *   6. record what was installed (detection marker), align the Docker image with the
 *      Java version the software needs and make sure the startup command launches the
 *      jar, refresh detection, optional start
 * A failure before step 5 leaves the server untouched and restarts it when it was
 * running; a failure during step 5 leaves it stopped and says so.
 *
 * Why unix_args.txt goes: the official Forge/NeoForge eggs start `@unix_args.txt` when
 * that file exists, so a server moved from Forge to Paper would keep booting the old
 * Forge. MCJars' Forge/NeoForge/Quilt archives ship a `server.jar` launcher instead,
 * which every family starts with `java … -jar {{SERVER_JARFILE}}`.
 */
final class SoftwareInstallerService
{
    /** Server-root folder downloads land in before they replace anything. */
    public const STAGING_DIR = '.mctoolkit-staging';

    /** How long to wait for the server to go offline after the stop signal. */
    private const STOP_WAIT_SECONDS = 60;

    /** Sanity cap on download steps per build. */
    private const MAX_DOWNLOADS = 20;

    /** Launcher MCJars' archive builds leave in the server root. */
    private const ARCHIVE_RUNNABLE = 'server.jar';

    /** Forge/NeoForge argument file the official eggs prefer over `-jar` when present. */
    private const UNIX_ARGS = 'unix_args.txt';

    public function __construct(
        private readonly FileBridge $files,
        private readonly DetectionService $detection,
        private readonly McJarsCatalogService $catalog,
    ) {}

    /**
     * @param  array{backup?: bool, start?: bool, java?: ?int, build?: ?string, restart_on_failure?: bool}  $options
     *         backup: pre-install archive (caller checked the permission); start: start once
     *         done; java: Java major when already known (Versions sheet); build: exact MCJars
     *         build name to install (loader version) — the latest build is used, with a note,
     *         when it is not listed; restart_on_failure: restart a server that was running
     *         when a failure happens before anything was replaced (default true)
     * @param  (Closure(string, ?int, ?int, array<string, scalar>): void)|null  $progress
     *         step key (pages.tasks.steps.*), done, total, label params; may throw
     *         {@see TaskSuperseded} to abort before live files are touched
     *
     * @throws SoftwareInstallException
     * @throws TaskSuperseded
     */
    public function install(Server $server, ?User $actor, string $type, string $version, array $options = [], ?Closure $progress = null): SoftwareInstallResult
    {
        $type = McJarsCatalogService::normalizeType($type);
        $version = trim($version);

        if ($type === null || $version === '') {
            throw new SoftwareInstallException(trans('minecraft-toolkit::errors.statuses.download_failed'));
        }

        $report = static function (string $step, ?int $done = null, ?int $total = null, array $params = []) use ($progress): void {
            if ($progress !== null) {
                $progress($step, $done, $total, $params);
            }
        };

        // After the swap began, losing the task entry must not abort a half-done swap.
        $softReport = static function (string $step, ?int $done = null, ?int $total = null) use ($report): void {
            try {
                $report($step, $done, $total);
            } catch (TaskSuperseded) {
                // finish what was started; the newer run waits for the server anyway
            }
        };

        $software = $this->softwareName($type);
        $notes = [];
        $attention = false;
        $wasRunning = false;
        $touched = false;
        $java = null;
        $build = null;

        try {
            $report('prepare');

            $build = $this->resolveBuild($type, $version, $options['build'] ?? null, $software, $notes, $attention);
            $plan = $build === null ? null : $this->planInstallation($build['installation'], $server);

            if ($plan === null) {
                throw new SoftwareInstallException(trans('minecraft-toolkit::errors.statuses.download_failed'), false, $notes);
            }

            // 1) every download lands in staging first; the server is not touched yet
            $this->deleteQuietly($server, self::STAGING_DIR);

            $count = count($plan['downloads']);

            foreach ($plan['downloads'] as $i => $download) {
                $report('download', $i, $count);
                $this->files->pull($server, $download['url'], '/'.$download['stage_dir'], $download['name']);
            }

            // 2) the container must be offline before binaries change
            $report('stop');
            $wasRunning = $this->stopAndAwaitOffline($server);

            // 3) optional archive of what is about to be replaced
            if ((bool) ($options['backup'] ?? false)) {
                $report('backup');
                $this->archiveCurrentInstall($server, $version);
            }

            // 4) swap: previous runnable jars out, staged files in, steps replayed. The
            //    report doubles as the last ownership check before live files change.
            $report('swap');

            $doomed = $this->previousSoftwareFiles($server, $plan);

            $touched = true;

            $this->files->delete($server, $doomed, '/');

            if (in_array(self::UNIX_ARGS, array_map('mb_strtolower', $doomed), true)) {
                $notes[] = trans('minecraft-toolkit::pages.tasks.notes.unix_args_removed');
            }

            $this->replaySteps($server, $plan);
            $runnable = $this->settleArchiveRunnable($server, $plan, $notes);
            $placed = $this->verifyPlaced($server, $plan, $runnable);
            $this->deleteQuietly($server, self::STAGING_DIR);

            // 5) remember what was installed (detection is re-run in `finally`)
            $this->writeInstallMarker($server, $type, $version, $build['name'], $placed);

            // 6) the software family decides the Java version and how it starts
            $softReport('configure');

            $java = $this->requiredJava($type, $version, $options['java'] ?? null);

            // The files are in place: a hiccup here is a note, never a failed install.
            try {
                $attention = $this->alignImage($server, $actor, $java, $notes) || $attention;
                $attention = $this->alignStartup($server, $actor, $notes) || $attention;
            } catch (Throwable $e) {
                report($e);

                $notes[] = trans('minecraft-toolkit::pages.tasks.notes.configure_failed');
                $attention = true;
            }

            $this->logActivity($server, $actor, $software, $version, $build['name']);
        } catch (TaskSuperseded $e) {
            throw $e;
        } catch (Throwable $e) {
            if (! $e instanceof SoftwareInstallException) {
                report($e);
            }

            $this->cleanupStaging($server);

            // Nothing was replaced yet: put the server back the way it was. A stop that
            // never confirmed offline is left alone — the container is mid-stop.
            if (! $touched && $wasRunning && ! $e instanceof StopTimedOut && ($options['restart_on_failure'] ?? true)) {
                $this->power($server, 'start', quiet: true); // node unreachable mid-recovery: nothing to add
            }

            if ($e instanceof SoftwareInstallException) {
                throw new SoftwareInstallException($e->getMessage(), $touched, $notes, $e->getPrevious() ?? $e);
            }

            $message = trans(match (true) {
                $e instanceof StopTimedOut => 'minecraft-toolkit::pages.versions.install.stop_timeout',
                $e instanceof BackupFailed => 'minecraft-toolkit::pages.versions.install.backup_failed',
                $e instanceof UnpackFailed => 'minecraft-toolkit::errors.statuses.unpack_failed',
                $touched => 'minecraft-toolkit::pages.versions.install.swap_failed',
                default => 'minecraft-toolkit::errors.statuses.download_failed',
            });

            if ($touched) {
                $message .= ' '.trans('minecraft-toolkit::pages.versions.install.left_stopped');
            }

            throw new SoftwareInstallException($message, $touched, $notes, $e);
        } finally {
            // After a swap (successful or not) the cached detection describes old files.
            if ($touched) {
                try {
                    $this->detection->detect($server, force: true);
                } catch (Throwable) {
                    // the next page load detects again
                }
            }
        }

        // 7) optional autostart
        $started = false;

        if ((bool) ($options['start'] ?? false)) {
            $softReport('start');

            if ($this->power($server, 'start')) {
                $started = true;
            } else {
                $notes[] = trans('minecraft-toolkit::pages.tasks.notes.start_failed');
                $attention = true;
            }
        }

        return new SoftwareInstallResult($type, $software, $version, $build['name'], $java, $notes, $started, $attention);
    }

    /**
     * Java major a build needs: the caller's value, else MCJars' versions row, else the
     * Minecraft version rule of thumb. Null when nothing tells (proxies, snapshots).
     */
    public function requiredJava(string $type, string $version, ?int $known = null): ?int
    {
        if ($known !== null && $known > 0) {
            return $known;
        }

        try {
            $listed = $this->catalog->javaFor($type, $version);
        } catch (Throwable) {
            $listed = null;
        }

        return $listed ?? self::fallbackJava($version);
    }

    /**
     * Java major by Minecraft version: < 1.17 → 8, 1.17.x → 16, 1.18–1.20.4 → 17,
     * 1.20.5–1.21.x → 21, 26.x and later → 25. Null for anything else.
     */
    public static function fallbackJava(string $mcVersion): ?int
    {
        $mcVersion = trim($mcVersion);

        if (preg_match('/^1\.(\d+)(?:\.(\d+))?/', $mcVersion, $m) === 1) {
            $minor = (int) $m[1];
            $patch = (int) ($m[2] ?? 0);

            return match (true) {
                $minor < 17 => 8,
                $minor === 17 => 16,
                $minor < 20, $minor === 20 && $patch <= 4 => 17,
                default => 21,
            };
        }

        if (preg_match('/^(\d{2,})\.\d+/', $mcVersion, $m) === 1 && (int) $m[1] >= 26) {
            return 25;
        }

        return null;
    }

    /**
     * Java major encoded in an egg image label or reference ("Java 21",
     * "ghcr.io/pelican-eggs/yolks:java_21", "eclipse-temurin JRE 17").
     */
    public static function javaMajorOf(string $label): ?int
    {
        return preg_match('/(?:java|jdk|jre)[^0-9]{0,3}(\d{1,2})(?!\d)/i', $label, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * The egg image for a Java major: exact match, else the smallest higher one. The
     * display name is read first, the image reference second.
     *
     * @param  array<string, string>  $images  display name => image
     * @return array{name: string, image: string, java: int}|null
     */
    public static function pickImage(array $images, int $java): ?array
    {
        $best = null;

        foreach ($images as $name => $image) {
            if (! is_string($image) || $image === '') {
                continue;
            }

            $major = self::javaMajorOf((string) $name) ?? self::javaMajorOf($image);

            if ($major === null || $major < $java) {
                continue;
            }

            if ($best === null || $major < $best['java']) {
                $best = ['name' => (string) $name, 'image' => $image, 'java' => $major];
            }
        }

        return $best;
    }

    /** Whether a startup command runs the server jar (`{{SERVER_JARFILE}}` or `-jar`). */
    public static function launchesJar(string $command): bool
    {
        return str_contains($command, '{{SERVER_JARFILE}}')
            || preg_match('/(?:^|\s)-jar(?:\s|$)/', $command) === 1;
    }

    // ------------------------------------------------------------------ build + plan

    /**
     * The requested build by name, else the latest build of the version (noted).
     *
     * @param  list<string>  $notes
     * @return array{name:string, installation:list<list<array<string, mixed>>>}|null
     */
    private function resolveBuild(string $type, string $version, ?string $buildName, string $software, array &$notes, bool &$attention): ?array
    {
        $buildName = $buildName === null ? '' : trim($buildName);

        if ($buildName !== '') {
            $exact = $this->catalog->buildByName($type, $version, $buildName);

            if ($exact !== null) {
                return $exact['build'];
            }
        }

        $latest = $this->catalog->latestBuild($type, $version);

        if ($latest === null) {
            return null;
        }

        if ($buildName !== '') {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.build_fallback', [
                'software' => $software,
                'build' => $buildName,
                'version' => $version,
                'latest' => $latest['build']['name'] !== '' ? $latest['build']['name'] : '?',
            ]);
            $attention = true;
        }

        return $latest['build'];
    }

    /**
     * Validate MCJars' installation groups and turn them into a download list plus an
     * ordered replay script. Any unknown step type, unsafe path or non-http(s) URL
     * rejects the whole build — a half-understood install would break the server.
     *
     * @param  list<list<array<string, mixed>>>  $installation
     * @return array{
     *     downloads: list<array{url: string, stage_dir: string, name: string, target: string}>,
     *     steps: list<array{type: string, from?: string, to?: string, file?: string, location?: string}>,
     *     targets: list<string>,
     *     expected: list<string>,
     *     root_unzip: bool,
     * }|null
     */
    private function planInstallation(array $installation, Server $server): ?array
    {
        $jarfile = $this->serverJarfile($server);
        $downloads = [];
        $steps = [];
        $rename = [];
        $removed = [];
        $rootUnzip = false;

        foreach ($installation as $group) {
            foreach ($group as $step) {
                $kind = (string) ($step['type'] ?? '');

                if ($kind === 'download') {
                    $url = $step['url'] ?? null;
                    $file = self::relativePath((string) ($step['file'] ?? ''));

                    if (! is_string($url) || preg_match('#^https?://#i', $url) !== 1 || $file === null || $file === '') {
                        return null;
                    }

                    $target = $file;

                    // MCJars names the runnable server.jar; the egg starts {{SERVER_JARFILE}}.
                    if ($file === self::ARCHIVE_RUNNABLE && $jarfile !== null && $jarfile !== self::ARCHIVE_RUNNABLE) {
                        $rename[$file] = $jarfile;
                        $target = $jarfile;
                    }

                    $stageDir = self::STAGING_DIR.'/'.count($downloads);
                    $name = basename($target);

                    $downloads[] = ['url' => $url, 'stage_dir' => $stageDir, 'name' => $name, 'target' => $target];
                    $steps[] = ['type' => 'place', 'from' => $stageDir.'/'.$name, 'to' => $target];

                    continue;
                }

                if ($kind === 'unzip') {
                    $file = self::relativePath((string) ($step['file'] ?? ''));
                    $location = self::relativePath((string) ($step['location'] ?? '.'));

                    if ($file === null || $file === '' || $location === null) {
                        return null;
                    }

                    $rootUnzip = $rootUnzip || $location === '';
                    $steps[] = ['type' => 'unzip', 'file' => $rename[$file] ?? $file, 'location' => $location];

                    continue;
                }

                if ($kind === 'remove') {
                    $location = self::relativePath((string) ($step['location'] ?? ''));

                    if ($location === null || $location === '') {
                        return null;
                    }

                    $location = $rename[$location] ?? $location;
                    $removed[] = $location;
                    $steps[] = ['type' => 'remove', 'location' => $location];

                    continue;
                }

                return null;
            }
        }

        if ($downloads === [] || count($downloads) > self::MAX_DOWNLOADS) {
            return null;
        }

        $targets = array_column($downloads, 'target');

        return [
            'downloads' => $downloads,
            'steps' => $steps,
            'targets' => $targets,
            // Downloaded files that should still exist once every step ran.
            'expected' => array_values(array_diff($targets, $removed)),
            'root_unzip' => $rootUnzip,
        ];
    }

    /**
     * Normalize a server-relative path from upstream: forward slashes, no leading slash,
     * no `.` segments; '' means the server root; null for traversal or NUL bytes.
     */
    private static function relativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', trim($path));

        if (str_contains($path, "\0")) {
            return null;
        }

        $segments = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return null;
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function directoryOf(string $relative): string
    {
        $slash = strrpos($relative, '/');

        return $slash === false ? '' : substr($relative, 0, $slash);
    }

    /**
     * The egg's SERVER_JARFILE when it is a plain root-level jar name, else null.
     */
    public function serverJarfile(Server $server): ?string
    {
        try {
            $variable = $server->variables()->where('env_variable', 'SERVER_JARFILE')->first();
        } catch (Throwable) {
            return null;
        }

        $value = self::relativePath((string) ($variable?->server_value ?? $variable?->default_value ?? ''));

        return $value !== null && $value !== '' && ! str_contains($value, '/') && str_ends_with(mb_strtolower($value), '.jar')
            ? $value
            : null;
    }

    // ------------------------------------------------------------------ swap

    /**
     * Pre-install archive: root runnable jars plus top-level world folders.
     */
    private function archiveCurrentInstall(Server $server, string $version): void
    {
        try {
            $names = [];

            foreach ($this->files->ls($server, '/') as $row) {
                $isJar = $row['file'] && str_ends_with(mb_strtolower($row['name']), '.jar');
                $isWorldDir = $row['directory'] && str_starts_with(mb_strtolower($row['name']), 'world');

                if ($isJar || $isWorldDir) {
                    $names[] = $row['name'];
                }
            }

            if ($names !== []) {
                $label = preg_replace('/[^A-Za-z0-9._-]+/', '_', $version) ?? 'version';

                $this->files->compress($server, '/', $names, 'mct-pre-'.$label.'-'.now()->format('YmdHis'), 'zip');
            }
        } catch (Throwable $e) {
            throw new BackupFailed('The pre-install archive could not be created.', 0, $e);
        }
    }

    /**
     * Root files to remove before the swap: the previous software's runnable jars
     * (anything detection recognises as server software, the egg's SERVER_JARFILE, and
     * every file the new build is about to place there), a Forge/NeoForge
     * `unix_args.txt`, and the stale install marker. Unrelated jars stay. Read-only — the
     * caller deletes.
     *
     * @param  array{targets: list<string>}  $plan
     * @return list<string>
     */
    private function previousSoftwareFiles(Server $server, array $plan): array
    {
        $targets = array_map('mb_strtolower', $plan['targets']);
        $jarfile = $this->serverJarfile($server);
        $jarfile = $jarfile === null ? null : mb_strtolower($jarfile);

        $doomed = [];

        foreach ($this->files->ls($server, '/') as $row) {
            if ($row['directory'] || ! ($row['file'] || $row['symlink'])) {
                continue;
            }

            $name = $row['name'];
            $lower = mb_strtolower($name);

            if ($lower === mb_strtolower(DetectionService::INSTALL_MARKER) || $lower === self::UNIX_ARGS) {
                $doomed[] = $name;

                continue;
            }

            if (str_ends_with($lower, '.jar')
                && ($this->detection->isServerSoftwareJar($name) || $lower === $jarfile || in_array($lower, $targets, true))) {
                $doomed[] = $name;
            }
        }

        return $doomed;
    }

    /**
     * Replay the validated steps: staged files move into place (wings' rename refuses an
     * existing target, so it is cleared first), archives are extracted into their
     * `location` (wings extracts next to the archive, so it is moved there first), and
     * `remove` steps delete their path — following a relocated archive.
     *
     * @param  array{steps: list<array{type: string, from?: string, to?: string, file?: string, location?: string}>}  $plan
     */
    private function replaySteps(Server $server, array $plan): void
    {
        $relocated = [];

        foreach ($plan['steps'] as $step) {
            switch ($step['type']) {
                case 'place':
                    $this->deleteQuietly($server, (string) $step['to']);
                    $this->files->rename($server, '/'.$step['from'], '/'.$step['to']);

                    break;

                case 'unzip':
                    $file = (string) $step['file'];
                    $location = (string) $step['location'];

                    if (self::directoryOf($file) !== $location) {
                        $moved = ltrim($location.'/'.basename($file), '/');

                        $this->deleteQuietly($server, $moved);
                        $this->files->rename($server, '/'.$file, '/'.$moved);

                        $relocated[$file] = $moved;
                        $file = $moved;
                    }

                    try {
                        $this->files->decompress($server, '/'.$location, basename($file));
                    } catch (Throwable $e) {
                        throw new UnpackFailed('The archive could not be unpacked.', 0, $e);
                    }

                    break;

                case 'remove':
                    $location = (string) $step['location'];

                    $this->deleteQuietly($server, $relocated[$location] ?? $location);

                    break;
            }
        }
    }

    /**
     * Archive builds (Forge, NeoForge, Quilt) unpack a `server.jar` launcher into the
     * root. The egg starts {{SERVER_JARFILE}}, so a differently named SERVER_JARFILE gets
     * the launcher under its own name. Returns the runnable's final name, null when the
     * build is not an archive build or left no launcher behind.
     *
     * @param  array{root_unzip: bool}  $plan
     * @param  list<string>  $notes
     */
    private function settleArchiveRunnable(Server $server, array $plan, array &$notes): ?string
    {
        if (! $plan['root_unzip']) {
            return null;
        }

        $launcher = null;

        foreach ($this->files->ls($server, '/') as $row) {
            if (! $row['directory'] && mb_strtolower($row['name']) === self::ARCHIVE_RUNNABLE) {
                $launcher = $row['name'];

                break;
            }
        }

        if ($launcher === null) {
            return null;
        }

        $jarfile = $this->serverJarfile($server);

        if ($jarfile === null || mb_strtolower($jarfile) === self::ARCHIVE_RUNNABLE) {
            return $launcher;
        }

        $this->deleteQuietly($server, $jarfile);
        $this->files->rename($server, '/'.$launcher, '/'.$jarfile);

        $notes[] = trans('minecraft-toolkit::pages.tasks.notes.runnable_renamed', ['jar' => $jarfile]);

        return $jarfile;
    }

    /**
     * Wings' rename answers 204 even when the source vanished, so check that every
     * root-level file the build should leave behind is really there.
     *
     * @param  array{expected: list<string>}  $plan
     * @return array<string, int> root-level placed file => size
     */
    private function verifyPlaced(Server $server, array $plan, ?string $runnable): array
    {
        $sizes = [];

        foreach ($this->files->ls($server, '/') as $row) {
            if (! $row['directory']) {
                $sizes[$row['name']] = $row['size'];
            }
        }

        $expected = $plan['expected'];

        if ($runnable !== null && ! in_array($runnable, $expected, true)) {
            $expected[] = $runnable;
        }

        $placed = [];

        foreach ($expected as $target) {
            if (str_contains($target, '/')) {
                continue;
            }

            if (! array_key_exists($target, $sizes)) {
                throw new \RuntimeException("Installed file [{$target}] is missing after the swap.");
            }

            $placed[$target] = $sizes[$target];
        }

        return $placed;
    }

    /**
     * Lets DetectionService recognise this install even though MCJars' runnable is a
     * generic `server.jar`. Skipped (and any older marker already swept) when no
     * root-level file of the build survives.
     *
     * @param  array<string, int>  $placed
     */
    private function writeInstallMarker(Server $server, string $type, string $version, string $buildName, array $placed): void
    {
        if ($placed === []) {
            return;
        }

        try {
            $this->files->write($server, DetectionService::INSTALL_MARKER, json_encode([
                'software' => $type,
                'version' => $version,
                'build' => $buildName,
                'files' => $placed,
                'installed_at' => now()->toIso8601String(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
        } catch (Throwable $e) {
            // Detection then falls back to file heuristics; the install itself succeeded.
            report($e);
        }
    }

    // ------------------------------------------------------------------ image + startup

    /**
     * Point the server at the egg image providing the needed Java (exact, else the
     * smallest higher major). Only when the current image is one of the egg's — a
     * custom image is the admin's call — and the actor may change it
     * (SubuserPermission::StartupDockerImage, same rule as the Startup page).
     *
     * @param  list<string>  $notes
     * @return bool whether a note asks the user to act
     */
    private function alignImage(Server $server, ?User $actor, ?int $java, array &$notes): bool
    {
        if ($java === null) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.java_unknown');

            return true;
        }

        $images = $this->eggList($server, 'docker_images');
        $current = (string) $server->image;
        $currentName = array_search($current, $images, true);

        if ($currentName === false) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.java_image_custom', ['java' => $java]);

            return true;
        }

        $choice = self::pickImage($images, $java);

        if ($choice === null) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.java_image_none', ['java' => $java]);

            return true;
        }

        $currentMajor = self::javaMajorOf((string) $currentName) ?? self::javaMajorOf($current);

        if ($choice['image'] === $current || $currentMajor === $choice['java']) {
            return false;
        }

        if ($actor === null || ! $actor->can(SubuserPermission::StartupDockerImage, $server)) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.java_image_denied', ['java' => $java, 'image' => $choice['name']]);

            return true;
        }

        $server->forceFill(['image' => $choice['image']])->saveOrFail();

        $this->logStartupChange($server, $actor, 'server:startup.image', ['old' => $current, 'new' => $choice['image']]);

        $notes[] = trans('minecraft-toolkit::pages.tasks.notes.java_image_switched', ['java' => $choice['java'], 'image' => $choice['name']]);

        return false;
    }

    /**
     * Make sure the server starts the jar: a startup taken from the egg that launches
     * neither {{SERVER_JARFILE}} nor `-jar` is switched to an egg command that does
     * (SubuserPermission::StartupUpdate). Custom commands are only noted.
     *
     * @param  list<string>  $notes
     * @return bool whether a note asks the user to act
     */
    private function alignStartup(Server $server, ?User $actor, array &$notes): bool
    {
        $current = (string) $server->startup;

        if (self::launchesJar($current)) {
            return false;
        }

        $commands = $this->eggList($server, 'startup_commands');
        $currentName = array_search($current, $commands, true);
        $target = null;

        foreach ($commands as $name => $command) {
            if (self::launchesJar($command)) {
                $target = (string) $name;

                break;
            }
        }

        if ($currentName === false || $target === null) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.startup_custom');

            return true;
        }

        if ($actor === null || ! $actor->can(SubuserPermission::StartupUpdate, $server)) {
            $notes[] = trans('minecraft-toolkit::pages.tasks.notes.startup_denied', ['name' => $target]);

            return true;
        }

        $server->forceFill(['startup' => $commands[$target]])->saveOrFail();

        $this->logStartupChange($server, $actor, 'server:startup.command', ['old' => (string) $currentName, 'new' => $target]);

        $notes[] = trans('minecraft-toolkit::pages.tasks.notes.startup_switched', ['name' => $target]);

        return false;
    }

    /**
     * @return array<string, string> egg display name => value
     */
    private function eggList(Server $server, string $attribute): array
    {
        try {
            $server->loadMissing('egg');
            $list = $server->egg?->getAttribute($attribute);
        } catch (Throwable) {
            return [];
        }

        $out = [];

        foreach (is_array($list) ? $list : [] as $name => $value) {
            if (is_string($value) && $value !== '') {
                $out[(string) $name] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $properties
     */
    private function logStartupChange(Server $server, User $actor, string $event, array $properties): void
    {
        try {
            Activity::event($event)
                ->subject($server)
                ->actor($actor)
                ->property($properties)
                ->log();
        } catch (Throwable) {
            // observability never undoes an applied change
        }
    }

    private function logActivity(Server $server, ?User $actor, string $software, string $version, string $buildName): void
    {
        try {
            $log = Activity::event('mc-toolkit:versions.install')
                ->subject($server)
                ->property([
                    'software' => $software,
                    'version' => $version,
                    'build_name' => $buildName,
                ]);

            if ($actor !== null) {
                $log->actor($actor); // no auth user inside a queue worker
            }

            $log->log();
        } catch (Throwable) {
            // observability never rolls back a finished install
        }
    }

    // ------------------------------------------------------------------ helpers

    /** Display name of a catalog type ("NeoForge"), falling back to "Neoforge". */
    public function softwareName(string $type): string
    {
        try {
            foreach ($this->catalog->types() as $types) {
                $name = $types[$type]['name'] ?? null;

                if (is_string($name) && trim($name) !== '') {
                    return trim($name);
                }
            }
        } catch (Throwable) {
            // catalog unreachable — fall back below
        }

        return ucfirst(mb_strtolower($type));
    }

    /**
     * Delete a server-relative path, treating "already gone" as success.
     */
    private function deleteQuietly(Server $server, string $relative): void
    {
        $relative = trim($relative, '/');

        if ($relative === '' || ! $this->files->exists($server, '/'.$relative)) {
            return;
        }

        try {
            $this->files->delete($server, [$relative], '/');
        } catch (RequestException $e) {
            if ($e->response->status() !== 404) {
                throw $e;
            }
        }
    }

    private function cleanupStaging(Server $server): void
    {
        try {
            $this->deleteQuietly($server, self::STAGING_DIR);
        } catch (Throwable) {
            // best-effort: a leftover staging folder is harmless and swept next time
        }
    }

    /**
     * Stops the container when it runs and waits until wings reports it offline,
     * clearing Server::retrieveStatus()'s 15-second cache before every read.
     *
     * @return bool whether the server was running (and should be restarted on failure)
     *
     * @throws StopTimedOut when it is still not offline after STOP_WAIT_SECONDS
     */
    private function stopAndAwaitOffline(Server $server): bool
    {
        $status = $this->freshStatus($server);

        if (self::isStopped($status)) {
            return false;
        }

        if ($status === ContainerStatus::Missing) {
            throw new \RuntimeException('The server state could not be read from the node.');
        }

        $wasRunning = $status !== ContainerStatus::Stopping;

        if ($status !== ContainerStatus::Stopping) {
            app(DaemonServerRepository::class)->setServer($server)->power('stop');
        }

        for ($i = 0; $i < self::STOP_WAIT_SECONDS; $i++) {
            sleep(1);

            try {
                if (self::isStopped($this->freshStatus($server))) {
                    return $wasRunning;
                }
            } catch (Throwable) {
                // connection hiccup — keep polling until budget spent
            }
        }

        throw new StopTimedOut;
    }

    private function freshStatus(Server $server): ContainerStatus
    {
        cache()->forget('servers.'.$server->uuid.'.status');

        return $server->retrieveStatus();
    }

    private static function isStopped(ContainerStatus $status): bool
    {
        return in_array($status, [ContainerStatus::Offline, ContainerStatus::Exited, ContainerStatus::Dead], true);
    }

    /** Power signal that never throws; false when the node refused it. */
    private function power(Server $server, string $signal, bool $quiet = false): bool
    {
        try {
            app(DaemonServerRepository::class)->setServer($server)->power($signal);

            return true;
        } catch (Throwable $e) {
            if (! $quiet) {
                report($e);
            }

            return false;
        }
    }
}

/**
 * Internal signal: the stop→offline wait burned its full budget.
 * Handled separately so we neither continue file IO nor auto-restart.
 */
final class StopTimedOut extends \RuntimeException {}

/** Internal signal: the requested pre-install archive failed (nothing replaced yet). */
final class BackupFailed extends \RuntimeException {}

/** Internal signal: wings could not extract an archive of the build (mid-swap). */
final class UnpackFailed extends \RuntimeException {}
