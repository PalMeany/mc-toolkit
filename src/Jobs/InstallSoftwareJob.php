<?php

declare(strict_types=1);

namespace McToolkit\Jobs;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Models\User;
use McToolkit\Filament\Server\Pages\VersionsPage;
use McToolkit\Services\Catalog\SoftwareInstallException;
use McToolkit\Services\Catalog\SoftwareInstallerService;

/**
 * Versions page install (stage MCJars downloads, stop & wait offline, optional
 * pre-install archive, swap, install marker, Java image / startup alignment, optional
 * start) run by the queue worker instead of the web request. See {@see ServerTaskJob}.
 */
final class InstallSoftwareJob extends ServerTaskJob
{
    public function __construct(
        int $serverId,
        int $userId,
        string $token,
        public readonly string $type,
        public readonly string $version,
        public readonly ?int $java = null,
        public readonly bool $backupFirst = false,
        public readonly bool $startAfter = true,
    ) {
        parent::__construct($serverId, $userId, $token);
    }

    /** Same gate as VersionsPage::canAccess() + canManage(). */
    protected function denialReason(Server $server, User $user): ?string
    {
        if ($server->isInConflictState()) {
            return trans('minecraft-toolkit::errors.statuses.server_unavailable');
        }

        $allowed = self::pageEnabled('versions')
            && $user->can('minecraft-toolkit.use', $server)
            && $user->can('minecraft-toolkit.versions', $server)
            && $user->can('minecraft-toolkit.manage', $server);

        return $allowed ? null : trans('minecraft-toolkit::errors.statuses.permission_denied');
    }

    protected function perform(Server $server, User $user): array
    {
        $backup = $this->backupFirst;

        // Backup rights may have been taken away (or the limit lowered) while queued.
        if ($backup && ! ((int) $server->backup_limit > 0 && $user->can(SubuserPermission::BackupCreate, $server))) {
            $backup = false;
            $this->note($server, [trans('minecraft-toolkit::pages.tasks.notes.backup_skipped')]);
        }

        try {
            $result = app(SoftwareInstallerService::class)->install($server, $user, $this->type, $this->version, [
                'backup' => $backup,
                'start' => $this->startAfter,
                'java' => $this->java,
            ], $this->reporter($server));
        } catch (SoftwareInstallException $e) {
            $this->note($server, $e->notes);

            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->note($server, $result->notes);

        return [
            'ok' => true,
            'message' => trans('minecraft-toolkit::pages.tasks.software_done', ['label' => $result->label()]),
            'warning' => $result->attention,
        ];
    }

    protected function pageUrl(Server $server): ?string
    {
        return VersionsPage::getUrl(panel: 'server', tenant: $server);
    }
}
