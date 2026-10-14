<?php

declare(strict_types=1);

namespace McToolkit\Filament\Server\Pages\Concerns;

use App\Models\Server;
use Filament\Notifications\Notification;
use Livewire\Attributes\Locked;
use McToolkit\Support\ServerTask;

/**
 * Page side of the background installs: holds the server's {@see ServerTask} snapshot
 * for the `<x-minecraft-toolkit::task-status>` card, polls it while the task is active
 * (the card adds `wire:poll.2s="pollTask"` only then) and lets permitted users cancel a
 * queued task or dismiss a finished / dead one.
 *
 * Hosting pages implement taskServer(), canControlTask() and may override
 * afterTaskFinished() to reload what the finished task changed.
 *
 * Livewire note: `$task` is a public property, so no method here may be named
 * hydrateTask / dehydrateTask / updatingTask / updatedTask — Livewire calls those for
 * the property and a non-public one throws.
 */
trait TracksServerTask
{
    /** Presented task snapshot (ServerTask::present()), null when the server has none. */
    #[Locked]
    public ?array $task = null;

    abstract protected function taskServer(): Server;

    /** Whether the current user may cancel / dismiss tasks of this server (read by the view too). */
    abstract public function canControlTask(): bool;

    /** Hook: the tracked task just left the active state (done, failed or dead). */
    protected function afterTaskFinished(array $task): void {}

    /** Livewire trait hook: show a task that is already running when the page opens. */
    public function mountTracksServerTask(): void
    {
        $this->refreshTaskSnapshot();
    }

    /** Polled every 2 s while the task is active. */
    public function pollTask(): void
    {
        $wasActive = ServerTask::isActive($this->task);

        $this->refreshTaskSnapshot();

        if ($wasActive && $this->task !== null && ! ServerTask::isActive($this->task)) {
            $this->afterTaskFinished($this->task);
        }
    }

    public function cancelTask(): void
    {
        if (! $this->canControlTask()) {
            $this->taskDenied();

            return;
        }

        $cancelled = ServerTask::cancel($this->taskServer());

        $notification = Notification::make()
            ->title(trans($cancelled ? 'minecraft-toolkit::pages.tasks.cancelled' : 'minecraft-toolkit::pages.tasks.cancel_too_late'));

        ($cancelled ? $notification->success() : $notification->warning())->send();

        $this->refreshTaskSnapshot();
    }

    public function dismissTask(): void
    {
        if (! $this->canControlTask()) {
            $this->taskDenied();

            return;
        }

        if (! ServerTask::dismiss($this->taskServer())) {
            Notification::make()
                ->title(trans('minecraft-toolkit::pages.tasks.still_running'))
                ->warning()
                ->send();
        }

        $this->refreshTaskSnapshot();
    }

    /** Whether the card shows an active task (used to disable install buttons). */
    public function taskIsActive(): bool
    {
        return ServerTask::isActive($this->task);
    }

    protected function refreshTaskSnapshot(): void
    {
        try {
            $this->task = ServerTask::present($this->taskServer());
        } catch (\Throwable) {
            $this->task = null; // cache hiccup: the next poll tries again
        }
    }

    /** Toast for a start attempt while another task still occupies the server. */
    protected function notifyTaskBusy(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::pages.versions.install.already_running'))
            ->body(trans('minecraft-toolkit::pages.tasks.busy_body'))
            ->warning()
            ->send();
    }

    /** Toast after a task was queued. */
    protected function notifyTaskQueued(string $label): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::pages.tasks.queued_title', ['label' => $label]))
            ->body(trans('minecraft-toolkit::pages.tasks.queued_body'))
            ->info()
            ->send();
    }

    private function taskDenied(): void
    {
        Notification::make()
            ->title(trans('minecraft-toolkit::errors.statuses.permission_denied'))
            ->danger()
            ->send();
    }
}
