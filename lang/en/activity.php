<?php

/**
 * Activity log labels for the toolkit's own events.
 *
 * Core renders event names by nesting the part before the colon, then the rest
 * (see lang/en/activity.php in the panel: 'server' => ['console' => ['command' => ...]]).
 * Every event emitted by this plugin ("mc-toolkit:<domain>.<action>") MUST have a
 * matching entry in the tree below; placeholders map 1:1 to ->property() keys.
 */
return [
    'mc-toolkit' => [
        'versions' => [
            // properties: software, version
            'install' => 'Installed <b>:software :version</b> on the server',
        ],

        'content' => [
            // properties: title (project name), source
            'install' => 'Installed <b>:title</b> from :source',
            // properties: title
            'uninstall' => 'Removed <b>:title</b> from the server',
            // properties: title, source
            'update' => 'Updated <b>:title</b> from :source',
        ],

        'properties' => [
            // properties: changed (comma-separated key list)
            'save' => 'Changed the server properties (<b>:changed</b>)',
        ],

        'players' => [
            // properties: action (op/kick/...), player
            'action' => 'Ran <b>:action</b> on <b>:player</b>',
        ],

        'worlds' => [
            // properties: world
            'archive' => 'Downloaded the world <b>:world</b>',
            // properties: world
            'reset' => 'Reset the world <b>:world</b>',
            // properties: world, url
            'import' => 'Imported a world from a remote archive as <b>:world</b>',
        ],

        'map' => [
            // properties: label, x, z
            'marker' => [
                'create' => 'Added marker <b>:label</b> at :x, :z',
                'delete' => 'Removed marker <b>:label</b>',
            ],
        ],

        'detection' => [
            'refresh' => 'Re-detected the server software from its files',
        ],

        'console' => [
            // properties: command — logged for every toolkit-originated game command batch
            'command' => 'Sent "<b>:command</b>" through the toolkit',
        ],
    ],
];
