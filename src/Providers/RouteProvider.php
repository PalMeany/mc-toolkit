<?php

declare(strict_types=1);

namespace McToolkit\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Mounts the two non-Livewire endpoints (download proxying + map tile JSON).
 *
 * Everything else in the toolkit is a Filament page and rides the panel's own
 * tenant-scoped routes (/server/{uuid_short}/mc-toolkit/...).
 */
class RouteProvider extends RouteServiceProvider
{
    public const WORLD_DOWNLOAD_LIMITER = 'mc-toolkit.download.world';

    public const MAP_TILE_LIMITER = 'mc-toolkit.map.tile';

    public function boot(): void
    {
        // Separate named limiters: a bare `throttle:x,y` keys on the user alone, so both
        // routes would share one counter and a map session would lock world downloads out.
        RateLimiter::for(self::WORLD_DOWNLOAD_LIMITER, fn (Request $request) => Limit::perMinute(6)
            ->by(self::WORLD_DOWNLOAD_LIMITER.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        RateLimiter::for(self::MAP_TILE_LIMITER, fn (Request $request) => Limit::perMinute(120)
            ->by(self::MAP_TILE_LIMITER.'|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        $this->routes(function () {
            // 'web' first: it starts the session that `auth` and `auth.session` read. The
            // panel replaces Laravel's middleware priority list, so the order written here is
            // the order that runs. Controllers still authorize the server themselves.
            Route::middleware(['web', 'auth', 'auth.session'])
                ->prefix('/server/{uuid_short}/mc-toolkit')
                ->group(plugin_path('minecraft-toolkit', 'routes/web.php'));
        });
    }
}
