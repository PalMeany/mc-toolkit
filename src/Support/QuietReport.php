<?php

declare(strict_types=1);

namespace McToolkit\Support;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Throwable;

/**
 * Error reporting for hot paths (map tiles, polling): a page can fire dozens of these per
 * minute, so a flaky node must not flood laravel.log with identical stack traces.
 *
 * - node timeouts / unreachable nodes / wings HTTP errors are expected operational states:
 *   never reported (the UI already shows the fallback);
 * - anything else is reported at most once per $key every 10 minutes.
 */
final class QuietReport
{
    private const WINDOW_SECONDS = 600;

    public static function report(Throwable $e, string $key): void
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof ConnectionException || $cause instanceof RequestException) {
                return;
            }
        }

        try {
            if (! cache()->add('mc-toolkit.report.'.sha1($key.'|'.$e::class.'|'.$e->getMessage()), true, self::WINDOW_SECONDS)) {
                return;
            }
        } catch (Throwable) {
            // cache down: fall through and report once more rather than never
        }

        report($e);
    }
}
