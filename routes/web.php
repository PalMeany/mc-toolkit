<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use McToolkit\Http\Controllers\MapTileController;
use McToolkit\Http\Controllers\WorldDownloadController;
use McToolkit\Providers\RouteProvider;

/*
|--------------------------------------------------------------------------
| Minecraft Toolkit — non-Livewire endpoints
|--------------------------------------------------------------------------
| Mounted by McToolkit\Providers\RouteProvider under the group
| ['web', 'auth', 'auth.session'] with prefix /server/{uuid_short}/mc-toolkit.
|
| {uuid_short} is resolved MANUALLY inside each controller (first-or-fail on
| Server::where('uuid_short', ...)) because the panel binds Filament tenants,
| not plain routes; every controller re-checks subuser permissions itself.
*/

// Named limiters (see RouteProvider) keyed per user and per route: 6 world zips and
// 120 tiles a minute keep node churn bounded without one route starving the other.
Route::get('download/world', WorldDownloadController::class)
    ->middleware('throttle:'.RouteProvider::WORLD_DOWNLOAD_LIMITER)
    ->name('mc-toolkit.download.world');

Route::get('map/tile', MapTileController::class)
    ->middleware('throttle:'.RouteProvider::MAP_TILE_LIMITER)
    ->name('mc-toolkit.map.tile');
