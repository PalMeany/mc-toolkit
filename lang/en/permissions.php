<?php

/*
|--------------------------------------------------------------------------
| minecraft-toolkit subuser permissions
|--------------------------------------------------------------------------
| Consumed by the Subusers screen through Subuser::registerCustomPermissions()
| with translation_prefix 'minecraft-toolkit::permissions'
| (app/Filament/Server/Resources/Subusers/SubuserResource.php:81-90):
|
|   tab label           -> trans('<prefix>.minecraft-toolkit_title')
|   tab description     -> trans('<prefix>.minecraft-toolkit_desc')
|   checkbox label      -> str('<perm>')->headline()  ("Use", "Manage", ...; not translated)
|   checkbox description-> trans('<prefix>.minecraft-toolkit_<perm>')  (dash->underscore in perm only)
|
| So each `minecraft-toolkit_<perm>` line below IS the description shown under its
| checkbox — keep it explanatory, the label already names the permission.
*/

return [
    // ---- Group -----------------------------------------------------------------
    'minecraft-toolkit_title' => 'Minecraft Toolkit',
    'minecraft-toolkit_desc' => 'Access to the Minecraft toolkit pages for this server.',

    // ---- Core access -------------------------------------------------------------
    'minecraft-toolkit_use' => 'See the toolkit sidebar and read server data (versions, worlds, map tiles, performance).',

    'minecraft-toolkit_manage' => 'Change things: install versions, plugins, mods and modpacks, edit properties, send player commands.',

    'minecraft-toolkit_administer' => 'Full access including destructive actions — resetting or importing worlds.',

    // ---- Fine-grained domains ----------------------------------------------------
    'minecraft-toolkit_players' => 'Open the Players page; with Manage also op, kick or ban players.',

    'minecraft-toolkit_content' => 'Browse Plugins, Mods and Modpacks catalogs; with Manage install or remove content.',

    'minecraft-toolkit_versions' => 'Open the Versions page; with Manage switch server software and builds.',

    'minecraft-toolkit_worlds' => 'Open the Worlds page; with Manage download, reset or import worlds.',

    'minecraft-toolkit_properties' => 'Open the Properties page; with Manage save changes into server.properties.',

    'minecraft-toolkit_map' => 'Open the Map page; with Manage create or remove markers.',
];
