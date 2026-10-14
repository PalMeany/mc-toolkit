<?php

/*
|--------------------------------------------------------------------------
| minecraft-toolkit subuser permissions
|--------------------------------------------------------------------------
| Consumed by the Subusers screen through Subuser::registerCustomPermissions()
| with translation_prefix 'minecraft-toolkit::permissions':
|
|   tab label      -> trans('<prefix>.minecraft-toolkit_title')
|   tab description-> trans('<prefix>.minecraft-toolkit_desc')
|   checkbox label -> trans('<prefix>.minecraft-toolkit_<perm>')   (dash->underscore in perm only)
|
| The matching `<perm>_desc` lines are contract-required per-perm descriptions
| (kept for any consumer that renders them under each checkbox).
*/

return [
    // ---- Group -----------------------------------------------------------------
    'minecraft-toolkit_title' => 'Minecraft Toolkit',
    'minecraft-toolkit_desc' => 'Access to the Minecraft toolkit pages for this server.',

    // ---- Core access -------------------------------------------------------------
    'minecraft-toolkit_use' => 'Open Minecraft Toolkit',
    'minecraft-toolkit_use_desc' => 'See the toolkit sidebar and read server data (versions, worlds, map tiles).',

    'minecraft-toolkit_manage' => 'Manage toolkit',
    'minecraft-toolkit_manage_desc' => 'Change things: install versions, plugins, mods and modpacks, edit properties, send player commands.',

    'minecraft-toolkit_administer' => 'Administer toolkit',
    'minecraft-toolkit_administer_desc' => 'Full access including destructive actions — resetting or importing worlds.',

    // ---- Fine-grained domains ----------------------------------------------------
    'minecraft-toolkit_players' => 'Players manager',
    'minecraft-toolkit_players_desc' => 'Open the Players page; with Manage also op, kick or ban players.',

    'minecraft-toolkit_content' => 'Content catalogs',
    'minecraft-toolkit_content_desc' => 'Browse Plugins, Mods and Modpacks catalogs; with Manage install or remove content.',

    'minecraft-toolkit_versions' => 'Versions',
    'minecraft-toolkit_versions_desc' => 'Open the Versions page; with Manage switch server software and builds.',

    'minecraft-toolkit_worlds' => 'Worlds',
    'minecraft-toolkit_worlds_desc' => 'Open the Worlds page; with Manage download, reset or import worlds.',

    'minecraft-toolkit_properties' => 'Properties',
    'minecraft-toolkit_properties_desc' => 'Open the Properties page; with Manage save changes into server.properties.',

    'minecraft-toolkit_map' => 'Map',
    'minecraft-toolkit_map_desc' => 'Open the Map page; with Manage create or remove markers.',
];
