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
        'compat_filter_version_label' => 'Made for Minecraft :version',

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
        'subheading' => 'Install a complete modpack on the server: its mods, its configs and the loader it runs on.',

        // Shown after an install (and on the pack row) when the detected software differs.
        // :loader -> e.g. "Fabric 0.15.11", :version -> e.g. "1.20.1"
        'requires' => 'This modpack runs on :loader for Minecraft :version — install that software from the Versions page before starting the server.',

        // Confirmation sheet of a modpack install / update (runs in the background).
        'confirm' => [
            // :title -> pack name, :version -> pack version ("Fabulously Optimized 6.4.0")
            'title' => 'Install :title',
            'update_title' => 'Update :title',
            'body' => 'The mods and configs of the pack are downloaded first, then put in place while the server is stopped. Worlds are kept.',
            // :loader -> "Fabric", :version -> Minecraft version
            'install_loader' => 'Also install :loader for Minecraft :version',
            'install_loader_hint' => 'Skipped when the server already runs it.',
            'loader_matches' => 'The server already runs :loader for Minecraft :version.',
            'loader_needs_versions' => 'Changing the server software needs the Versions permission — install :loader for :version from the Versions page afterwards.',
            'loader_unknown' => 'This modpack does not name its loader; the server software is left as it is.',
            // :loader -> "Fabric"; shown on Bukkit-family servers and proxies
            'switches_loader' => 'This switches the server to :loader — the plugins of the current software stop loading.',
            'start_after' => 'Start the server when it is done',
            'no_archive' => 'This modpack publishes no .mrpack archive the server could install.',
        ],
    ],

    // Background installs (queued jobs) — the task card on the Versions and Modpacks pages.
    'tasks' => [
        'heading' => 'Background task',
        'kinds' => [
            'software' => 'Software install',
            'modpack' => 'Modpack install',
        ],
        'states' => [
            'queued' => 'Queued',
            'running' => 'Running',
            'done' => 'Finished',
            'failed' => 'Failed',
            'dead' => 'Stalled',
        ],
        // Step keys reported by the installers.
        'steps' => [
            'queued' => 'Waiting for the queue worker',
            'prepare' => 'Preparing',
            'download' => 'Downloading the server files',
            'stop' => 'Stopping the server',
            'backup' => 'Archiving the current files',
            'swap' => 'Putting the new files in place',
            'configure' => 'Checking the Java image and the startup command',
            'start' => 'Starting the server',
            'pack_archive' => 'Downloading the modpack archive',
            'pack_unpack' => 'Unpacking the modpack',
            'pack_files' => 'Downloading the modpack files',
            'pack_moving' => 'Moving the modpack files into place',
        ],
        // :done / :total -> counters of the current step
        'progress' => ':done of :total',
        'dismiss' => 'Dismiss',
        // :time -> relative time ("3 minutes ago")
        'queued_at' => 'Queued :time',
        'started_at' => 'Started :time',
        'finished_at' => 'Finished :time',
        'queue_hint' => 'Still waiting after two minutes: the panel\'s queue worker (the pelican-queue service, `php artisan queue:work`) may not be running.',
        'dead_hint' => 'No sign of life for over ten minutes — the run probably died. Dismiss it and check the server files before starting it.',
        // :label -> "Paper 1.21.4" / pack title
        'queued_title' => ':label is queued',
        'queued_body' => 'It runs in the background — you can leave this page; you get a notification when it is done.',
        'busy_body' => 'Wait until the running task has finished, or dismiss it once it has stalled.',
        'still_running' => 'The task is still running.',
        'cancelled' => 'The queued task was cancelled.',
        'cancel_too_late' => 'The task already started and can no longer be cancelled.',
        'queue_failed' => 'The task could not be queued.',
        'failed_unexpected' => 'The task stopped on an unexpected error — see the panel log, and check the server files before starting it.',
        // :minutes -> config minecraft-toolkit.tasks.timeout / 60
        'timed_out' => 'The task took longer than :minutes minutes and was stopped. Check the server files before starting it.',
        'software_done' => ':label is installed.',
        // :title -> pack name + version
        'modpack_done' => ':title is installed.',
        // :count -> notes left out of a notification
        'more_notes' => '… and :count more on the page.',

        'notify' => [
            // :label -> task label, :server -> server name
            'done' => ':label — done on :server',
            'failed' => ':label — failed on :server',
        ],

        // Adjustments made or skipped during a run (task notes + final notification).
        'notes' => [
            // :software, :build, :version, :latest
            'build_fallback' => ':software build :build is not listed for :version, so the latest build (:latest) was installed instead.',
            'runnable_renamed' => 'The server jar was renamed to :jar, the file the startup command launches.',
            'unix_args_removed' => 'Removed unix_args.txt of the previous Forge/NeoForge install so the new jar starts.',
            // :java -> Java major, :image -> egg image name ("Java 21")
            'java_image_switched' => 'Docker image switched to ":image": this version needs Java :java.',
            'java_image_denied' => 'This software needs Java :java (image ":image"), but you may not change the Docker image — ask the owner or pick it on the Startup page.',
            'java_image_custom' => 'This server uses a custom Docker image — make sure it provides Java :java.',
            'java_image_none' => 'None of the egg\'s Docker images provides Java :java or newer — pick a fitting image on the Startup page.',
            'java_unknown' => 'The Java version this software needs is unknown — check the Docker image on the Startup page.',
            // :name -> egg startup command name
            'startup_switched' => 'The startup command was switched to ":name" so it launches the server jar.',
            'startup_denied' => 'The startup command does not launch the server jar and you may not change it — pick ":name" on the Startup page.',
            'startup_custom' => 'The startup command does not launch the server jar ({{SERVER_JARFILE}} or -jar) — check it on the Startup page.',
            'configure_failed' => 'The Docker image and the startup command could not be checked — review them on the Startup page.',
            'backup_skipped' => 'No pre-install archive was made: this server cannot create backups for you any more.',
            'start_failed' => 'The server could not be started — the node did not answer the start signal.',
            'start_skipped' => 'The server was not started because the loader the modpack needs is not installed.',
            'pack_untouched' => 'The modpack files were not moved into place; the server files are unchanged.',
            // :loader -> "Fabric 0.16.10", :version -> Minecraft version
            'loader_installed' => ':loader for Minecraft :version was installed for the modpack.',
            'plugins_unloaded' => 'The server now runs :loader: the plugins of the previous software no longer load.',
            'loader_matches' => 'The server already runs :loader for Minecraft :version.',
            'loader_denied' => 'The modpack needs :loader for Minecraft :version, but you may not change the server software — install it from the Versions page.',
            // :reason -> failure message of the software install
            'loader_failed' => 'The modpack is installed, but :loader for Minecraft :version could not be installed: :reason',
            'loader_none' => 'The modpack does not name a loader; the server software was left as it is.',
        ],
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
        'no_regions' => 'No explored region files were found in :world yet. They appear once the server has saved some chunks.',
        'no_world' => 'No world with region files was found on this server yet.',

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
            'needed' => 'Needs the companion bridge',
        ],
    ],
];
