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
| over bare upstream names (CURSEFORGE_KEY / HANGAR_KEY) so other tooling can
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

// Comma-separated enabled slug list; blank, missing or the literal "all" turn on everything.
$rawEnabledPages = trim((string) env('MC_TOOLKIT_ENABLED_PAGES', ''));

$enabledPages = [];
foreach ($pages as $page) {
    if ($rawEnabledPages === '' || str_contains($rawEnabledPages, 'all')) {
        $enabledPages[$page] = true;
        continue;
    }

    $enabledPages[$page] = str_contains($rawEnabledPages, $page);
}

$curseforgeKey = env('MC_TOOLKIT_CURSEFORGE_KEY') ?? env('CURSEFORGE_KEY');
$hangarKey = env('MC_TOOLKIT_HANGAR_KEY') ?? env('HANGAR_KEY');

return [
    // ---- Catalog credentials -------------------------------------------------
    'curseforge_key' => $curseforgeKey,
    'hangar_key' => $hangarKey,

    // ---- Versions catalog ----------------------------------------------------
    'mcjars_base' => 'https://mcjars.app',

    // ---- Content registries --------------------------------------------------
    'registries' => [
        'enable' => [
            'modrinth' => true,
            'spigot' => true,
            // Key-gated sources stay disabled while their credential is missing —
            // adapters themselves also return null without keys (belt and braces).
            'hangar' => ($hangarKey !== null && trim((string) $hangarKey) !== '') ? true : false,
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

    // ---- Companion bridge ------------------------------------------------------
    'bridge' => [
        // Directory inside each server's files where the future companion mod writes state.
        'path' => '.toolkit',
    ],
];
