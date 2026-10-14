<?php

declare(strict_types=1);

namespace McToolkit\Support;

use App\Facades\Activity as ActivityFacade;
use App\Services\Activity\ActivityLogService;

/**
 * Typed passthrough for the core activity logger facade.
 *
 * `App\Facades\Activity` (accessor: App\Services\Activity\ActivityLogService) exists in Pelican
 * v1.0.0-rc.1, so plugin services should PREFER importing that facade directly:
 *
 *     Activity::event('mc-toolkit:versions.install')->property('version', '1.21.3')->log();
 *
 * This class only exists for IDE typing convenience and places that prefer a stable,
 * namespaced import. It forwards to the facade verbatim; continue chaining the fluent
 * {@see ActivityLogService} methods (->property()->subject()->log()) on its return value in
 * a single expression, exactly like core does.
 *
 * Event names must follow the contract: 'mc-toolkit:<domain>.<action>', with matching labels
 * defined under lang/en/activity.php.
 */
final class Activity
{
    /**
     * Opens an activity log entry for the given event name.
     *
     * The underlying service is NOT a singleton, so this is always used as
     * Activity::event(...)->...->log() inside one chained expression.
     */
    public static function event(string $event): ActivityLogService
    {
        return ActivityFacade::event($event);
    }
}
