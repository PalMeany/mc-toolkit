<?php

declare(strict_types=1);

namespace McToolkit\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider;
use Illuminate\Support\Facades\Route;

/**
 * Mounts the two non-Livewire endpoints (download proxying + map tile JSON).
 *
 * Everything else in the toolkit is a Filament page and rides the panel's own
 * tenant-scoped routes (/server/{uuid_short}/mc-toolkit/...).
 */
class RouteProvider extends RouteServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            Route::middleware(['auth.session', 'web'])
                ->prefix('/server/{uuid_short}/mc-toolkit')
                ->group(plugin_path('minecraft-toolkit', 'routes/web.php'));
        });
    }
}
