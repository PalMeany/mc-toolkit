<?php

declare(strict_types=1);

namespace McToolkit\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * How the panel runs queued jobs, and the dispatch rule that follows from it.
 *
 * Pelican ships no config/queue.php, so Laravel's default applies (QUEUE_CONNECTION,
 * `database` unless the admin changed it) and the pelican-queue service runs one
 * `php artisan queue:work`. Panels switched to the `sync` driver run a job inside the
 * dispatching request instead; there long installs are deferred until the response was
 * sent (->afterResponse()), so the page answers at once and polls the task tracker.
 */
final class QueueMode
{
    /** Whether the default connection runs jobs inline. */
    public static function isSync(): bool
    {
        $connection = (string) config('queue.default', 'sync');

        return $connection === 'sync'
            || (string) config("queue.connections.{$connection}.driver", $connection) === 'sync';
    }

    /** Queue a long-running job; on `sync` it runs after the response instead of inside it. */
    public static function dispatchLong(ShouldQueue $job): void
    {
        if (self::isSync()) {
            dispatch($job)->afterResponse();

            return;
        }

        dispatch($job);
    }
}
