<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages\Concerns;

use App\Enums\SubuserPermission;
use App\Models\Server;
use App\Repositories\Daemon\DaemonServerRepository;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use McToolkit\Services\Bridge\BridgeService;

/**
 * Companion-bridge state + the one-click install/restart actions shared by the pages that
 * show bridge data (Performance, Players, Map). Rendered by the bridge-callout component.
 *
 * Install needs the page's domain permission (checked by canAccess() on every request) plus
 * `minecraft-toolkit.manage`; the restart button needs the panel's own restart permission.
 */
trait ManagesBridge
{
    /** BridgeService::status() snapshot; null until loaded. */
    #[Locked]
    public ?array $bridgeStatus = null;

    protected function loadBridgeStatus(bool $force = false): void
    {
        try {
            $this->bridgeStatus = app(BridgeService::class)->status($this->bridgeServer(), $force);
        } catch (\Throwable $e) {
            report($e);
            $this->bridgeStatus = null;
        }
    }

    public function canInstallBridge(): bool
    {
        $server = $this->bridgeServer();

        return ! $server->isInConflictState()
            && (bool) user()?->can('minecraft-toolkit.manage', $server)
            && (bool) ($this->bridgeStatus['supported'] ?? false);
    }

    public function canRestartForBridge(): bool
    {
        $server = $this->bridgeServer();

        return ! $server->isInConflictState() && (bool) user()?->can(SubuserPermission::ControlRestart, $server);
    }

    public function installBridge(): void
    {
        $this->loadBridgeStatus(true);

        if (! $this->canInstallBridge()) {
            Notification::make()->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))->danger()->send();

            return;
        }

        try {
            $result = app(BridgeService::class)->install($this->bridgeServer(), user());
        } catch (\DomainException $e) {
            Notification::make()->title($e->getMessage())->danger()->send();

            return;
        }

        Notification::make()
            ->title(trans('minecraft-toolkit::pages.bridge.installed_toast'))
            ->body(trans('minecraft-toolkit::pages.bridge.installed_toast_body'))
            ->success()
            ->send();

        $this->loadBridgeStatus(true);
    }

    public function restartForBridge(): void
    {
        if (! $this->canRestartForBridge()) {
            Notification::make()->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))->danger()->send();

            return;
        }

        $server = $this->bridgeServer();

        try {
            app(DaemonServerRepository::class)->setServer($server)->power('restart');
            cache()->forget("servers.{$server->uuid}.status");
        } catch (\Throwable $e) {
            report($e);
            Notification::make()->title(trans('minecraft-toolkit::errors.statuses.wings_502'))->danger()->send();

            return;
        }

        Notification::make()->title(trans('minecraft-toolkit::pages.bridge.restarting_toast'))->success()->send();

        app(BridgeService::class)->forget($server);
    }

    private function bridgeServer(): Server
    {
        /** @var Server $server */
        $server = Filament::getTenant();

        return $server;
    }
}
