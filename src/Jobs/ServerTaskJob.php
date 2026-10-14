<?php

declare(strict_types=1);

namespace McToolkit\Jobs;

use App\Models\Server;
use App\Models\User;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\TimeoutExceededException;
use McToolkit\Support\ServerTask;
use McToolkit\Support\TaskSuperseded;
use Throwable;

/**
 * Shared shell of the long-running installs that moved out of the web request.
 *
 * Contract with {@see ServerTask}: the page creates a queued tracker entry and hands its
 * token to the job. The job
 *   - re-loads server and user (both may be gone or changed while it waited),
 *   - claims its entry (ServerTask::begin) — a cancelled or replaced entry, or a second
 *     delivery of an already started job, ends the run before anything happens,
 *   - RE-CHECKS permissions and the conflict state (they may change while queued),
 *   - reports progress/heartbeats through {@see self::reporter()}, which throws
 *     {@see TaskSuperseded} once the entry belongs to somebody else,
 *   - finishes the entry and sends a Filament database notification to the user.
 *
 * Queue semantics: one attempt only (a half-done install must never be replayed), its
 * own timeout (config `minecraft-toolkit.tasks.timeout`, honoured by the worker over its
 * --timeout flag) and fail-on-timeout. With the database driver's retry_after (90 s by
 * default) a second worker may pick a still-running job up again; that delivery dies with
 * MaxAttemptsExceededException and {@see self::failed()} must then leave the live run
 * alone — recognisable by its fresh heartbeat.
 *
 * Panels on the `sync` driver run the job after the response (QueueMode::dispatchLong);
 * there PHP's time limit and user-abort handling are lifted for the run, as the
 * in-request installs always did.
 */
abstract class ServerTaskJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;

    /** Never replay a partially applied install. */
    public int $tries = 1;

    /** Seconds; set from config in the constructor. */
    public int $timeout = 3600;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $serverId,
        public readonly int $userId,
        public readonly string $token,
    ) {
        $this->timeout = max(60, (int) config('minecraft-toolkit.tasks.timeout', 3600));
    }

    /**
     * Why the user may not run this task any more (translated), null when allowed.
     */
    abstract protected function denialReason(Server $server, User $user): ?string;

    /**
     * The work itself. Expected failures come back as an outcome, unexpected ones throw.
     *
     * @return array{ok: bool, message: ?string, warning?: bool}
     */
    abstract protected function perform(Server $server, User $user): array;

    /** Page the finish notification links to. */
    abstract protected function pageUrl(Server $server): ?string;

    public function handle(): void
    {
        $server = Server::query()->find($this->serverId);

        if (! $server instanceof Server || ! ServerTask::begin($server, $this->token)) {
            return; // server deleted, task cancelled/replaced, or this token already ran
        }

        $restore = $this->liftLimits();
        $user = User::query()->find($this->userId);

        try {
            if (! $user instanceof User) {
                ServerTask::finish($server, $this->token, false, trans('minecraft-toolkit::errors.statuses.permission_denied'));

                return;
            }

            $denied = $this->denialReason($server, $user);

            if ($denied !== null) {
                $this->conclude($server, $user, ['ok' => false, 'message' => $denied]);

                return;
            }

            $this->conclude($server, $user, $this->perform($server, $user));
        } catch (TaskSuperseded) {
            // A newer run owns the server and its tracker entry now: stay silent.
        } catch (Throwable $e) {
            report($e);

            $this->conclude($server, $user, ['ok' => false, 'message' => trans('minecraft-toolkit::pages.tasks.failed_unexpected')]);
        } finally {
            $restore();
        }
    }

    /**
     * Called by the worker when the job failed outside handle(): timeout, or a duplicate
     * delivery (MaxAttemptsExceededException) — the latter is ignored while the original
     * run is still alive.
     */
    public function failed(?Throwable $exception): void
    {
        try {
            $server = Server::query()->find($this->serverId);

            if (! $server instanceof Server) {
                return;
            }

            $task = ServerTask::get($server);

            if ($task === null || $task['id'] !== $this->token) {
                return;
            }

            $timedOut = $exception instanceof TimeoutExceededException;

            if (! $timedOut && $exception instanceof MaxAttemptsExceededException && ServerTask::hasFreshHeartbeat($task)) {
                return; // second delivery while the first run keeps working
            }

            $message = $timedOut
                ? trans('minecraft-toolkit::pages.tasks.timed_out', ['minutes' => intdiv($this->timeout, 60)])
                : trans('minecraft-toolkit::pages.tasks.failed_unexpected');

            if (ServerTask::finish($server, $this->token, false, $message)) {
                $this->notify(User::query()->find($this->userId), $server, false, $message, false);
            }
        } catch (Throwable) {
            // the failed() hook must never throw itself
        }
    }

    /**
     * Progress callback for the installers: step key, done, total, label params. Every
     * call is a heartbeat; once the tracker entry is no longer this run's it throws.
     *
     * @return Closure(string, ?int, ?int, array<string, scalar>): void
     */
    protected function reporter(Server $server): Closure
    {
        return function (string $step, ?int $done = null, ?int $total = null, array $params = []) use ($server): void {
            if (! ServerTask::step($server, $this->token, $step, $done, $total, $params)) {
                throw new TaskSuperseded('The tracker entry of this run is gone.');
            }
        };
    }

    /**
     * @param  iterable<string>  $notes
     */
    protected function note(Server $server, iterable $notes): void
    {
        foreach ($notes as $note) {
            ServerTask::note($server, $this->token, $note);
        }
    }

    /** Whether the page of this task kind is switched on in the admin settings. */
    protected static function pageEnabled(string $page): bool
    {
        return (bool) config("minecraft-toolkit.pages.enabled.{$page}", true);
    }

    /**
     * @param  array{ok: bool, message: ?string, warning?: bool}  $outcome
     */
    private function conclude(Server $server, ?User $user, array $outcome): void
    {
        $ok = $outcome['ok'];
        $warning = $ok && (bool) ($outcome['warning'] ?? false);

        if (ServerTask::finish($server, $this->token, $ok, $outcome['message'], $warning)) {
            $this->notify($user, $server, $ok, $outcome['message'], $warning);
        }
    }

    /**
     * Database notification (the panels use ->databaseNotifications()): the outcome, then
     * the task notes. Note texts may carry catalog names, so they are escaped before
     * the HTML line breaks are added.
     */
    private function notify(?User $user, Server $server, bool $ok, ?string $message, bool $warning): void
    {
        if (! $user instanceof User) {
            return;
        }

        $task = ServerTask::get($server);
        $label = (string) ($task['label'] ?? '');
        $notes = array_values(array_filter((array) ($task['notes'] ?? []), 'is_string'));

        $lines = array_map('e', array_filter([$message, ...array_slice($notes, 0, 8)], fn ($line): bool => is_string($line) && trim($line) !== ''));

        if (count($notes) > 8) {
            $lines[] = e(trans('minecraft-toolkit::pages.tasks.more_notes', ['count' => count($notes) - 8]));
        }

        try {
            $notification = Notification::make()
                ->title(trans($ok ? 'minecraft-toolkit::pages.tasks.notify.done' : 'minecraft-toolkit::pages.tasks.notify.failed', [
                    'label' => $label,
                    'server' => (string) $server->name,
                ]))
                ->body($lines === [] ? null : implode('<br>', $lines));

            match (true) {
                ! $ok => $notification->danger(),
                $warning => $notification->warning(),
                default => $notification->success(),
            };

            $url = null;

            try {
                $url = $this->pageUrl($server);
            } catch (Throwable) {
                // no panel routes in this context — the notification simply has no link
            }

            if ($url !== null) {
                $notification->actions([
                    Action::make('open')
                        ->label(trans('minecraft-toolkit::common.buttons.open'))
                        ->url($url)
                        ->markAsRead(),
                ]);
            }

            $notification->sendToDatabase($user);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Inside a web request (sync driver, after the response) a run may take far longer
     * than max_execution_time and must survive a closed tab. Hosts may disable either
     * function; PHP 8 then throws on the call, hence the guards.
     *
     * @return Closure(): void restores the previous settings
     */
    private function liftLimits(): Closure
    {
        if (app()->runningInConsole()) {
            return static function (): void {};
        }

        $canLiftLimit = function_exists('set_time_limit');
        $canIgnoreAbort = function_exists('ignore_user_abort');
        $originalTimeLimit = (int) ini_get('max_execution_time');
        $originalIgnoreAbort = $canIgnoreAbort ? ignore_user_abort(true) : 0;

        if ($canLiftLimit) {
            @set_time_limit(0);
        }

        return static function () use ($canLiftLimit, $canIgnoreAbort, $originalTimeLimit, $originalIgnoreAbort): void {
            if ($canIgnoreAbort) {
                ignore_user_abort((bool) $originalIgnoreAbort);
            }

            if ($canLiftLimit) {
                @set_time_limit($originalTimeLimit);
            }
        };
    }
}
