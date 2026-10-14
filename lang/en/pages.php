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
            'already_running' => 'Another installation is still running on this server.',
            'stop_timeout' => 'The server did not stop in time, so nothing was changed.',
            'backup_failed' => 'The backup could not be created, so nothing was changed.',
            'swap_failed' => 'The new server files could not be put in place.',
            'left_stopped' => 'The server was left stopped — check its files before starting it again.',
        ],

        'backup_unavailable' => 'This server cannot create backups.',

        'show_more' => 'Show more versions',
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
        'subheading' => 'Install a complete modpack on the server: its mods and its configs. Its loader is installed from the Versions page.',

        // Shown after an install (and on the pack row) when the detected software differs.
        // :loader -> e.g. "Fabric 0.15.11", :version -> e.g. "1.20.1"
        'requires' => 'This modpack runs on :loader for Minecraft :version — install that software from the Versions page before starting the server.',
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

        'load_failed' => 'server.properties could not be read, so saving is disabled. Refresh the page to try again.',
        'save_failed' => 'server.properties could not be saved. Nothing was changed.',
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

        // Stand-alone whitelist field, usable without selecting a player first.
        'whitelist_add' => [
            'label' => 'Add a player to the whitelist',
            'placeholder' => 'Player name',
            'button' => 'Add to whitelist',
        ],

        'errors' => [
            'invalid_name' => 'Player names are 1-16 letters, digits or underscores (Bedrock players may start with a dot), so this one was not sent to the console.',
            'invalid_item' => 'Use lowercase letters, digits, underscores or colons for the item id.',
        ],
    ],

    'worlds' => [
        'title' => 'Worlds',
        'subheading' => 'The worlds of the server: play on another one, download, import or reset them, and install maps made by the community.',

        'import_link' => 'Import a world',
        'maps_browse' => 'Download maps',

        'import_url' => 'Archive URL (.zip, .tar.gz…)',
        'import_target' => 'Name of the imported world (optional)',
        // :world -> folder name the world was imported as
        'import_done' => 'The world was imported as :world. Choose "Play on" to make it the active world.',
        'import_name_invalid' => 'World names may only use letters, digits, spaces, dots, dashes and underscores.',
        'import_no_world' => 'The archive does not contain a Minecraft world (no level.dat or region folder was found).',
        'unknown_world' => 'This world folder was not found on the server any more. Refresh the page and try again.',
        'reset_note' => 'These folders are deleted for good. The server has to be stopped first.',

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

        // :count -> MapTileService::MAX_REGIONS_PER_SCAN (the regions nearest to spawn
        // are drawn first; the rest of a huge world is left out).
        'regions_truncated' => 'Huge world: only the :count regions nearest to spawn are drawn.',

        'redraw' => 'Redraw',

        'delete_marker' => 'Remove marker',

        'dimensions' => [
            'overworld' => 'Overworld',
            'nether' => 'Nether',
            'end' => 'End',
        ],
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
            'heading' => 'Companion bridge not found',
            'unavailable' => 'TPS, MSPT and the per-world entity and chunk counts need the companion bridge — CPU, memory and uptime come from the node and keep working below.',
            'hint' => 'These figures are read from .toolkit/performance.json, which only the companion bridge writes, and this server has no such file.',
        ],
    ],
];
