<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use McToolkit\Http\Controllers\MapTileController;
use McToolkit\Http\Controllers\WorldDownloadController;

/*
|--------------------------------------------------------------------------
| Minecraft Toolkit — non-Livewire endpoints
|--------------------------------------------------------------------------
| Mounted by McToolkit\Providers\RouteProvider under the group
| ['auth.session', 'web'] with prefix /server/{uuid_short}/mc-toolkit.
|
| {uuid_short} is resolved MANUALLY inside each controller (first-or-fail on
| Server::where('uuid_short', ...)) because the panel binds Filament tenants,
| not plain routes; every controller re-checks subuser permissions itself.
*/

// Throttles are keyed per authenticated user id (auth.session already ran);
// 6 world zips / minute and 60 tiles / minute keep node churn bounded for a
// logged-in holder of the worlds / map permissions looping either URL.
Route::get('download/world', WorldDownloadController::class)
    ->middleware('throttle:6,1')
    ->name('mc-toolkit.download.world');

Route::get('map/tile', MapTileController::class)
    ->middleware('throttle:60,1')
    ->name('mc-toolkit.map.tile');
