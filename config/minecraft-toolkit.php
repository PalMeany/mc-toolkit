<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Minecraft Toolkit configuration
|--------------------------------------------------------------------------
| Merged automatically by the plugin loader (App\Services\Helpers\PluginService),
| read everywhere as config('minecraft-toolkit.*').
|
| Env precedence: MC_TOOLKIT_* keys written by the admin settings slide-over win
| over the bare upstream name (CURSEFORGE_KEY) so other tooling can
| pre-define them.
*/

$pages = [
    'versions',
    'plugins',
    'mods',
    'modpacks',
    'properties',
    'players',
    'worlds',
    'map',
    'performance',
];

// Comma-separated enabled slug list; blank, missing or the literal "all" turn on everything,
// "none" (written by the settings form when every box is unticked) turns everything off.
$rawEnabledPages = strtolower(trim((string) env('MC_TOOLKIT_ENABLED_PAGES', '')));
$listedPages = array_map('trim', explode(',', $rawEnabledPages));
$allPagesEnabled = $rawEnabledPages === '' || in_array('all', $listedPages, true);

$enabledPages = [];
foreach ($pages as $page) {
    $enabledPages[$page] = $allPagesEnabled || in_array($page, $listedPages, true);
}

$curseforgeKey = env('MC_TOOLKIT_CURSEFORGE_KEY') ?? env('CURSEFORGE_KEY');

return [
    // ---- Catalog credentials -------------------------------------------------
    'curseforge_key' => $curseforgeKey,

    // ---- Versions catalog ----------------------------------------------------
    'mcjars_base' => 'https://mcjars.app',

    // ---- Content registries --------------------------------------------------
    'registries' => [
        'enable' => [
            'modrinth' => true,
            'spigot' => true,
            // Key-gated sources stay disabled while their credential is missing —
            // adapters themselves also return null without keys (belt and braces).
            // Hangar's read API is public; no key involved.
            'hangar' => true,
            'curseforge' => ($curseforgeKey !== null && trim((string) $curseforgeKey) !== '') ? true : false,
        ],
    ],

    // ---- Cache lifetimes (seconds) ------------------------------------------
    'cache_ttl' => [
        'types' => 21600,   // mcjars type/version catalog: 6h
        'searches' => 600,  // registry search pages: 10m
        'detect' => 900,    // filesystem software detection: 15m
    ],

    // ---- Server-sidebar page toggles ----------------------------------------
    'pages' => [
        'enabled' => $enabledPages,
    ],

    // ---- Map renderer ---------------------------------------------------------
    'map' => [
        // How long one rendered region tile may be cached (seconds).
        'tile_cache_ttl' => 900,
    ],

    // ---- Hard limits -----------------------------------------------------------
    'limits' => [
        // Largest .mrpack accepted for a modpack pull (200 MiB).
        'mrpack_max_bytes' => 209715200,
        // Most bytes pulled per region file while rendering map tiles (8 MiB).
        'region_fetch_max_bytes' => 8388608,
    ],

    // ---- Background installs (queued jobs) -------------------------------------
    'tasks' => [
        // Upper bound of one queued software/modpack install, in seconds. Laravel's worker
        // honours a job's own timeout over its --timeout flag; a run that outlives it is
        // stopped and marked failed.
        'timeout' => (int) env('MC_TOOLKIT_TASK_TIMEOUT', 3600),
        // A task still queued after this many seconds hints that no queue worker is running.
        'stalled_after' => 120,
        // A running task without a heartbeat for this long counts as dead and can be dismissed.
        'dead_after' => 600,
    ],

    // ---- Companion bridge ------------------------------------------------------
    'bridge' => [
        // Directory inside each server's files where the future companion mod writes state.
        'path' => '.toolkit',
    ],
];
