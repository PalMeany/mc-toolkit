<?php

return [
    'versions' => [
        'title' => 'Versions',
        'subheading' => 'Install or change the server software (Paper, Purpur, Fabric, Forge...) and its version.',

        'filter' => [
            'recommended' => 'Recommended',
            'established' => 'Established',
            'experimental' => 'Experimental',
            'miscellaneous' => 'Miscellaneous',
            'limbo' => 'Limbo',
        ],

        'tab' => [
            'releases' => 'Releases',
            'snapshots' => 'Snapshots',
            'all' => 'All',
        ],

        'install' => [
            // Title renders as: "Install :software :version"
            'title' => 'Install :software :version',
            'body' => 'The server software is replaced. The worlds, the plugins and the settings are kept.',
            'backup_first' => 'Create a backup first',
            'start_after' => 'Start the server when it is done',
            'stopped_note' => 'The server is stopped during the installation.',
        ],

        'backup_unavailable' => 'This server cannot create backups.',
    ],

    'plugins' => [
        'title' => 'Plugins',
        'subheading' => 'Find and install plugins from Modrinth, Hangar, SpigotMC and CurseForge, and keep them up to date.',

        'sources' => [
            'modrinth' => 'Modrinth',
            'hangar' => 'Hangar',
            'spigot' => 'SpigotMC',
            'curseforge' => 'CurseForge',
        ],

        // :loaders -> e.g. "paper, spigot, bukkit",  :version -> detected MC version
        'compat_filter_label' => 'Compatible with my server (:loaders · :version)',

        'foreign_section' => [
            'title' => 'Other files',
            'desc' => 'Jars in your plugins folder that were not installed by the toolkit. They are listed for awareness — the toolkit leaves them alone.',
        ],

        'no_toolkit' => [
            'title' => 'This server does not load plugins',
            'desc' => 'Plugin catalogs need a Paper, Purpur, Spigot or another Bukkit-family server. Change the software of the server in the Versions page.',
        ],

        'installs_count' => '{count} installed',
    ],

    'mods' => [
        'title' => 'Mods',
        'subheading' => 'Find and install mods from Modrinth and CurseForge, with their dependencies.',

        'blocker' => [
            'bukkit_message' => 'Plugins are loaded on this server instead of mods. The Mods page needs a Fabric, Quilt, Forge or NeoForge server — change it in the Versions page.',
        ],

        'installs_count' => '{count} installed',
    ],

    'modpacks' => [
        'title' => 'Modpacks',
        'subheading' => 'Install a complete modpack on the server: its loader, its mods and its configs.',
    ],

    'properties' => [
        'title' => 'Properties',
        'subheading' => 'Change the settings of the server: MOTD, players, world, PvP... saved straight into server.properties.',

        'group' => [
            'general' => 'General',
            'players' => 'Players',
            'world' => 'World',
            'network' => 'Network & RCON',
            'advanced' => 'Advanced',
        ],

        // Shown (disabled) next to panel-owned keys such as server-port / server-ip.
        'managed_by_panel' => 'Set by the panel',
    ],

    'players' => [
        'title' => 'Players',
        'subheading' => 'The inventory, health and permissions of your players, changed live in the game.',

        'toolbar' => [
            // ":online of :max online" — demo parity ("3 / 50 online")
            'online_of' => ':online of :max online',
            'instant_tooltip' => 'Changes made here appear at once while the companion bridge is active.',
            // Honest limitation disclosure: the status-ping sample only shares a few
            // player names at once, so per-card online flags stay approximate.
            'sample_note' => 'The game shares only a few names at once, so some online players may still appear offline here.',
        ],

        'controls' => [
            'gamemode' => 'Game mode',
            'xp_levels' => 'Experience levels',
            'operator' => 'Operator',
            'whitelisted' => 'Whitelisted',
            'banned' => 'Banned',
        ],

        'states' => [
            'tier2_missing' => 'Live inventory, health and positions need a companion bridge on your Minecraft server. Everything else on this page works without one.',
        ],
    ],

    'worlds' => [
        'title' => 'Worlds',
        'subheading' => 'The worlds of the server: play on another one, download, import or reset them, and install maps made by the community.',

        'import_link' => 'Import a world',
        'maps_browse' => 'Download maps',

        'rows' => [
            'part_of_default' => 'Part of the default world: downloaded, reset and deleted with it.',
        ],
    ],

    'map' => [
        'title' => 'Map',
        'subheading' => 'The world as it is, seen from above, with the players in real time and your markers.',

        // :world -> active world name
        'render_note' => 'Drawn from the blocks of :world as colored block tiles. Unexplored chunks stay black until players reveal them.',

        'layers' => [
            'markers' => 'Markers',
            'players' => 'Players',
            'spawn' => 'Spawn',
            'world_border' => 'World border',
        ],

        'follow_on' => 'Following a player',
        'follow_off' => 'Stop following',

        // :count -> MapTileService::MAX_REGIONS_PER_SCAN (only the first N regions
        // of a huge world get rasterized before the scan stops).
        'regions_truncated' => 'Huge world: only the first :count regions of this world are drawn.',

        'redraw' => 'Redraw',
    ],

    'performance' => [
        'title' => 'Performance',
        'subheading' => 'The TPS and MSPT of the server, live, with the entities and chunks of each world to find what makes it lag.',

        'tiles' => [
            'tps' => 'TPS',
            'mspt' => 'MSPT',
            'cpu' => 'CPU',
            'memory' => 'Memory',
            'chunks' => 'Chunks',
            'entities' => 'Entities',
        ],

        'worlds_header' => 'Worlds breakdown',

        'bridge' => [
            'unavailable' => 'Per-world entity and chunk counts need the companion bridge — live TPS, MSPT, CPU and memory still work below.',
        ],
    ],
];
