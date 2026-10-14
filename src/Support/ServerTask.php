<?php

declare(strict_types=1);

namespace McToolkit\Support;

use App\Models\Server;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;

/**
 * Cache-backed tracker of the ONE background task (queued software or modpack install)
 * a server may have at a time.
 *
 * Why a tracker and not just the queue: the queue knows nothing about servers, the page
 * needs to show what is happening while the request that started it is long gone, and
 * two installs on one server must never overlap. The entry lives under
 * `mctoolkit.task.<server uuid>` and holds:
 *
 *   id (job token), kind, label, state (queued|running|done|failed), step (+ params),
 *   done/total progress, message, warning flag, notes, user_id,
 *   queued_at / started_at / finished_at / heartbeat_at (unix seconds)
 *
 * Life cycle:
 *   start()  — page: atomically creates a queued entry unless another task is active
 *   begin()  — job: queued → running, only for its own token (a cancelled or replaced
 *              entry makes the job bail out without touching the server)
 *   step()/note() — job: progress + heartbeat; false once the entry is no longer its own
 *   finish() — job: running → done|failed
 *   cancel() — page: drops a still-queued entry; dismiss() — page: drops a finished or
 *              dead one
 *
 * "Active" means queued, or running with a heartbeat younger than `tasks.dead_after`
 * (default 10 min); a dead run no longer blocks the server and can be dismissed. Every
 * mutation runs under a short cache lock (stores without lock support simply skip it).
 * Timestamps come from now(), so tests can travel in time.
 */
final class ServerTask
{
    public const QUEUED = 'queued';

    public const RUNNING = 'running';

    public const DONE = 'done';

    public const FAILED = 'failed';

    public const KIND_SOFTWARE = 'software';

    public const KIND_MODPACK = 'modpack';

    /** Defaults of the `minecraft-toolkit.tasks.*` thresholds. */
    public const STALLED_AFTER_SECONDS = 120;

    public const DEAD_AFTER_SECONDS = 600;

    private const LOCK_SECONDS = 10;

    private const LOCK_WAIT_SECONDS = 5;

    private const MAX_NOTES = 50;

    private const MAX_TEXT = 400;

    public static function key(Server $server): string
    {
        return CacheKeys::PREFIX.'.task.'.$server->uuid;
    }

    /**
     * Raw entry (including the job token), null when the server has none.
     *
     * @return array<string, mixed>|null
     */
    public static function get(Server $server): ?array
    {
        $task = cache()->get(self::key($server));

        return is_array($task) && is_string($task['id'] ?? null) ? $task : null;
    }

    /**
     * View model for the pages: the entry without its token, plus derived flags
     * (active, finished, queue_stalled, dead, percent, can_cancel, can_dismiss).
     *
     * @return array<string, mixed>|null
     */
    public static function present(Server $server): ?array
    {
        $task = self::get($server);

        if ($task === null) {
            return null;
        }

        $now = self::now();
        $state = (string) ($task['state'] ?? '');
        $queued = $state === self::QUEUED;
        $running = $state === self::RUNNING;
        $finished = in_array($state, [self::DONE, self::FAILED], true);
        $dead = $running && self::isDead($task, $now);
        $done = (int) ($task['done'] ?? 0);
        $total = (int) ($task['total'] ?? 0);

        return [
            'ref' => substr(hash('sha256', (string) $task['id']), 0, 12),
            'kind' => (string) ($task['kind'] ?? ''),
            'label' => (string) ($task['label'] ?? ''),
            'state' => $state,
            'step' => is_string($task['step'] ?? null) ? $task['step'] : null,
            'step_params' => is_array($task['step_params'] ?? null) ? $task['step_params'] : [],
            'done' => $done,
            'total' => $total,
            'percent' => $total > 0 ? (int) min(100, max(0, floor($done * 100 / $total))) : null,
            'message' => is_string($task['message'] ?? null) ? $task['message'] : null,
            'warning' => (bool) ($task['warning'] ?? false),
            'notes' => array_values(array_filter((array) ($task['notes'] ?? []), 'is_string')),
            'queued_at' => self::intOrNull($task['queued_at'] ?? null),
            'started_at' => self::intOrNull($task['started_at'] ?? null),
            'finished_at' => self::intOrNull($task['finished_at'] ?? null),
            'active' => $queued || ($running && ! $dead),
            'finished' => $finished,
            'queue_stalled' => $queued && $now - (int) ($task['queued_at'] ?? $now) > self::stalledAfter(),
            'dead' => $dead,
            'can_cancel' => $queued,
            'can_dismiss' => $finished || $dead,
        ];
    }

    /** Whether an entry (raw or presented) still occupies its server. */
    public static function isActive(?array $task): bool
    {
        if ($task === null) {
            return false;
        }

        if (array_key_exists('active', $task)) {
            return (bool) $task['active'];
        }

        $state = (string) ($task['state'] ?? '');

        return $state === self::QUEUED || ($state === self::RUNNING && ! self::isDead($task, self::now()));
    }

    /**
     * Atomically queue a new task. Returns its token, or null while another task of
     * this server is still active (finished and dead entries are replaced).
     */
    public static function start(Server $server, string $kind, string $label, ?int $userId): ?string
    {
        return self::mutate($server, function (?array $current) use ($kind, $label, $userId): array {
            if (self::isActive($current)) {
                return [null, false];
            }

            $token = bin2hex(random_bytes(16));

            return [$token, [
                'id' => $token,
                'kind' => $kind,
                'label' => mb_substr($label, 0, 120),
                'state' => self::QUEUED,
                'step' => 'queued',
                'step_params' => [],
                'done' => 0,
                'total' => 0,
                'message' => null,
                'warning' => false,
                'notes' => [],
                'user_id' => $userId,
                'queued_at' => self::now(),
                'started_at' => null,
                'finished_at' => null,
                'heartbeat_at' => null,
            ]];
        });
    }

    /**
     * Job entry point: queued → running for the job's own token only. False when the
     * task was cancelled, replaced, or this token already ran (second delivery).
     */
    public static function begin(Server $server, string $token): bool
    {
        return self::mutate($server, function (?array $current) use ($token): array {
            if ($current === null || $current['id'] !== $token || ($current['state'] ?? null) !== self::QUEUED) {
                return [false, false];
            }

            $now = self::now();

            return [true, array_merge($current, [
                'state' => self::RUNNING,
                'step' => 'prepare',
                'step_params' => [],
                'started_at' => $now,
                'heartbeat_at' => $now,
            ])];
        });
    }

    /** Whether the entry is still this token's running task. */
    public static function owns(Server $server, string $token): bool
    {
        $current = self::get($server);

        return $current !== null && $current['id'] === $token && ($current['state'] ?? null) === self::RUNNING;
    }

    /**
     * Progress + heartbeat. `$step` is a pages.tasks.steps.* key; null keeps the step.
     * False once the entry no longer belongs to this running token.
     *
     * @param  array<string, scalar>  $params
     */
    public static function step(Server $server, string $token, ?string $step, ?int $done = null, ?int $total = null, array $params = []): bool
    {
        return self::mutate($server, function (?array $current) use ($token, $step, $done, $total, $params): array {
            if ($current === null || $current['id'] !== $token || ($current['state'] ?? null) !== self::RUNNING) {
                return [false, false];
            }

            $next = $current;
            $next['heartbeat_at'] = self::now();

            if ($step !== null) {
                $stepChanged = $step !== ($current['step'] ?? null);
                $next['step'] = $step;
                $next['step_params'] = $params;

                // A new step starts its own counter unless the caller sets one.
                if ($stepChanged && $done === null && $total === null) {
                    $next['done'] = 0;
                    $next['total'] = 0;
                }
            }

            if ($done !== null) {
                $next['done'] = max(0, $done);
            }

            if ($total !== null) {
                $next['total'] = max(0, $total);
            }

            return [true, $next];
        });
    }

    /** Append one note (also a heartbeat). False once the entry is no longer this token's. */
    public static function note(Server $server, string $token, string $note): bool
    {
        $note = trim($note);

        return self::mutate($server, function (?array $current) use ($token, $note): array {
            if ($current === null || $current['id'] !== $token || ($current['state'] ?? null) !== self::RUNNING) {
                return [false, false];
            }

            if ($note === '') {
                return [true, false];
            }

            $notes = array_values(array_filter((array) ($current['notes'] ?? []), 'is_string'));

            if (! in_array($note, $notes, true) && count($notes) < self::MAX_NOTES) {
                $notes[] = mb_substr($note, 0, self::MAX_TEXT);
            }

            return [true, array_merge($current, ['notes' => $notes, 'heartbeat_at' => self::now()])];
        });
    }

    /**
     * queued|running → done|failed for this token (a still-queued entry is finished too:
     * a job whose server vanished, or failed() before the job ever began).
     */
    public static function finish(Server $server, string $token, bool $ok, ?string $message = null, bool $warning = false): bool
    {
        return self::mutate($server, function (?array $current) use ($token, $ok, $message, $warning): array {
            if ($current === null || $current['id'] !== $token || ! in_array($current['state'] ?? null, [self::QUEUED, self::RUNNING], true)) {
                return [false, false];
            }

            $now = self::now();

            return [true, array_merge($current, [
                'state' => $ok ? self::DONE : self::FAILED,
                'step' => null,
                'step_params' => [],
                'message' => $message === null ? null : mb_substr(trim($message), 0, self::MAX_TEXT),
                'warning' => $ok && $warning,
                'finished_at' => $now,
                'heartbeat_at' => $now,
            ])];
        });
    }

    /** Drop a still-queued task (its job bails out at begin()). False when it already started. */
    public static function cancel(Server $server): bool
    {
        return self::mutate($server, function (?array $current): array {
            if ($current === null || ($current['state'] ?? null) !== self::QUEUED) {
                return [false, false];
            }

            return [true, null];
        });
    }

    /** Clear a finished task or a dead run. False while the task is still active. */
    public static function dismiss(Server $server): bool
    {
        return self::mutate($server, function (?array $current): array {
            if ($current === null) {
                return [true, false];
            }

            if (self::isActive($current)) {
                return [false, false];
            }

            return [true, null];
        });
    }

    /**
     * Whether the run behind a raw entry still shows signs of life (used by failed() to
     * tell a duplicate delivery of a running job from a real failure).
     *
     * @param  array<string, mixed>  $task
     */
    public static function hasFreshHeartbeat(array $task): bool
    {
        return ($task['state'] ?? null) === self::RUNNING && ! self::isDead($task, self::now());
    }

    // ------------------------------------------------------------------ internals

    /**
     * Read-modify-write under the per-server lock. The callback returns
     * [result, next entry | null (forget) | false (unchanged)].
     *
     * @param  Closure(?array<string, mixed>): array{0: mixed, 1: array<string, mixed>|null|false}  $change
     */
    private static function mutate(Server $server, Closure $change): mixed
    {
        $apply = function () use ($server, $change): mixed {
            [$result, $next] = $change(self::get($server));

            if ($next === null) {
                cache()->forget(self::key($server));
            } elseif ($next !== false) {
                cache()->put(self::key($server), $next, now()->addSeconds(self::ttl()));
            }

            return $result;
        };

        try {
            $lock = cache()->lock(self::key($server).'.lock', self::LOCK_SECONDS);
        } catch (\Throwable) {
            return $apply(); // cache store without lock support
        }

        try {
            return $lock->block(self::LOCK_WAIT_SECONDS, $apply);
        } catch (LockTimeoutException) {
            // A writer hung for longer than the lock lives: proceed best-effort rather than
            // dropping a state change.
            return $apply();
        }
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private static function isDead(array $task, int $now): bool
    {
        $last = self::intOrNull($task['heartbeat_at'] ?? null)
            ?? self::intOrNull($task['started_at'] ?? null)
            ?? self::intOrNull($task['queued_at'] ?? null)
            ?? $now;

        return $now - $last > self::deadAfter();
    }

    /** Entries outlive the longest run; finished ones linger until dismissed or replaced. */
    private static function ttl(): int
    {
        return max(86400, 2 * (int) config('minecraft-toolkit.tasks.timeout', 3600));
    }

    private static function stalledAfter(): int
    {
        return max(10, (int) config('minecraft-toolkit.tasks.stalled_after', self::STALLED_AFTER_SECONDS));
    }

    private static function deadAfter(): int
    {
        return max(60, (int) config('minecraft-toolkit.tasks.dead_after', self::DEAD_AFTER_SECONDS));
    }

    private static function now(): int
    {
        return now()->getTimestamp();
    }

    private static function intOrNull(mixed $value): ?int
    {
        return is_int($value) ? $value : (is_numeric($value) ? (int) $value : null);
    }
}
