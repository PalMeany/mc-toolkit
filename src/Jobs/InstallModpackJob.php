<?php

declare(strict_types=1);

namespace McToolkit\Jobs;

use App\Models\Server;
use App\Models\User;
use App\Repositories\Daemon\DaemonServerRepository;
use DomainException;
use McToolkit\Filament\Server\Pages\ModpacksPage;
use McToolkit\Models\InstalledContent;
use McToolkit\Services\Catalog\ContentInstallerService;
use McToolkit\Services\Catalog\SoftwareInstallException;
use McToolkit\Services\Catalog\SoftwareInstallerService;
use McToolkit\Services\Content\UpdateScanner;
use McToolkit\Services\DetectionService;
use Throwable;

/**
 * Modrinth `.mrpack` install run by the queue worker — hundreds of sequential wings
 * pulls never fit a web request.
 *
 *   1. the pack itself (ContentInstallerService: staged downloads, the server must stay
 *      stopped until the files are moved into place);
 *   2. the pack's loader (modrinth.index.json `dependencies`) through the
 *      SoftwareInstallerService, unless the detected software already matches — the
 *      MCJars build named like the pack's loader version, else the latest one. Needs
 *      the Versions ability as well (minecraft-toolkit.versions + manage), otherwise the
 *      step is skipped with a note pointing to the Versions page;
 *   3. the server stays stopped unless the user asked for a start AND the loader is in
 *      place (a pack never starts on the wrong software).
 *
 * See {@see ServerTaskJob} for the queue/tracker contract.
 */
final class InstallModpackJob extends ServerTaskJob
{
    /** Pack loader id => MCJars type id. */
    private const LOADER_TYPES = [
        'fabric' => 'FABRIC',
        'quilt' => 'QUILT',
        'forge' => 'FORGE',
        'neoforge' => 'NEOFORGE',
    ];

    /** Pack loader id => display name. */
    public const LOADER_NAMES = [
        'fabric' => 'Fabric',
        'quilt' => 'Quilt',
        'forge' => 'Forge',
        'neoforge' => 'NeoForge',
    ];

    public function __construct(
        int $serverId,
        int $userId,
        string $token,
        public readonly string $projectId,
        public readonly string $title,
        public readonly bool $isUpdate = false,
        public readonly bool $installLoader = true,
        public readonly bool $startAfter = false,
    ) {
        parent::__construct($serverId, $userId, $token);
    }

    /** Same gate as the Modpacks page (canAccessContentCatalog() + userCanMutate()). */
    protected function denialReason(Server $server, User $user): ?string
    {
        if ($server->isInConflictState()) {
            return trans('minecraft-toolkit::errors.statuses.server_unavailable');
        }

        $allowed = self::pageEnabled('modpacks')
            && $user->can('minecraft-toolkit.use', $server)
            && $user->can('minecraft-toolkit.content', $server)
            && $user->can('minecraft-toolkit.manage', $server);

        return $allowed ? null : trans('minecraft-toolkit::errors.statuses.permission_denied');
    }

    /** Whether the user may also change the server software (VersionsPage::canManage()). */
    public static function mayInstallLoader(Server $server, User $user): bool
    {
        return ! $server->isInConflictState()
            && $user->can('minecraft-toolkit.versions', $server)
            && $user->can('minecraft-toolkit.manage', $server);
    }

    protected function perform(Server $server, User $user): array
    {
        $installer = app(ContentInstallerService::class)->actingAs($user);

        try {
            $previousLoader = (string) (app(DetectionService::class)->detect($server)['loader'] ?? 'vanilla');
        } catch (Throwable) {
            $previousLoader = 'vanilla';
        }

        try {
            $row = $installer->installProject($server, 'modpack', [
                'source' => 'modrinth',
                'id' => $this->projectId,
                'title' => $this->title,
            ], [], $this->isUpdate, $this->reporter($server));
        } catch (DomainException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } finally {
            $this->forgetUpdateScan($server);
        }

        [$loaderState, $attention] = $this->ensureLoader($server, $user, $installer, $row, $previousLoader);

        if ($this->startAfter) {
            if (in_array($loaderState, ['installed', 'matches', 'none'], true)) {
                if (! $this->startServer($server)) {
                    $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.start_failed')]);
                    $attention = true;
                }
            } else {
                $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.start_skipped')]);
            }
        }

        $title = $row->displayTitle() !== '' ? $row->displayTitle() : $this->title;

        return [
            'ok' => true,
            'message' => trans('minecraft-toolkit::pages.tasks.modpack_done', ['title' => $title.($row->version !== null ? ' '.$row->version : '')]),
            'warning' => $attention,
        ];
    }

    /**
     * Step 2. Returns the loader state (installed|matches|none|skipped|failed) and
     * whether a note asks the user to act.
     *
     * @return array{0: string, 1: bool}
     */
    private function ensureLoader(Server $server, User $user, ContentInstallerService $installer, InstalledContent $row, string $previousLoader): array
    {
        $deps = is_array($row->dependencies) ? $row->dependencies : [];
        $requires = is_array($deps['summary']['requires'] ?? null) ? $deps['summary']['requires'] : [];

        $loader = is_string($requires['loader'] ?? null) ? strtolower($requires['loader']) : null;
        $minecraft = is_string($requires['minecraft'] ?? null) && trim($requires['minecraft']) !== '' ? trim($requires['minecraft']) : null;
        $loaderVersion = is_string($requires['loader_version'] ?? null) && trim($requires['loader_version']) !== '' ? trim($requires['loader_version']) : null;

        if ($loader === null || $minecraft === null || ! isset(self::LOADER_TYPES[$loader])) {
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.loader_none')]);

            return ['none', false];
        }

        $name = self::LOADER_NAMES[$loader];
        $labelled = trim($name.' '.($loaderVersion ?? ''));
        $params = ['loader' => $labelled, 'version' => $minecraft];

        if (($requires['matches_server'] ?? false) === true) {
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.loader_matches', $params)]);

            return ['matches', false];
        }

        if (! $this->installLoader) {
            $this->note($server, [trans('minecraft-toolkit::pages.modpacks.requires', $params)]);

            return ['skipped', true];
        }

        if (! self::mayInstallLoader($server, $user)) {
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.loader_denied', $params)]);

            return ['skipped', true];
        }

        try {
            $result = app(SoftwareInstallerService::class)->install($server, $user, self::LOADER_TYPES[$loader], $minecraft, [
                'build' => $loaderVersion,
                'start' => false,
                'backup' => false,
                'restart_on_failure' => false,
            ], $this->reporter($server));
        } catch (SoftwareInstallException $e) {
            $this->note($server, $e->notes);
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.loader_failed', $params + ['reason' => $e->getMessage()])]);

            return ['failed', true];
        }

        // Loader first, then what the installer adjusted (image, startup, runnable name).
        $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.loader_installed', [
            'loader' => trim($name.' '.$result->buildName),
            'version' => $minecraft,
        ])]);

        // Plugins of a Bukkit-family server (or a proxy) stop loading on a mod loader.
        if (in_array($previousLoader, ['bukkit', 'velocity'], true)) {
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.plugins_unloaded', ['loader' => $name])]);
        }

        $this->note($server, $result->notes);

        $installer->recheckPackRequirements($server, $row);

        return ['installed', $result->attention];
    }

    private function startServer(Server $server): bool
    {
        try {
            app(DaemonServerRepository::class)->setServer($server)->power('start');

            return true;
        } catch (Throwable $e) {
            report($e);

            return false;
        }
    }

    /** The installed list's update dots are computed against the tracking rows. */
    private function forgetUpdateScan(Server $server): void
    {
        try {
            app(UpdateScanner::class)->forget($server);
        } catch (Throwable) {
            // the snapshot expires on its own
        }
    }

    protected function pageUrl(Server $server): ?string
    {
        return ModpacksPage::getUrl(panel: 'server', tenant: $server);
    }
}
