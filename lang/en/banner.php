<?php

return [
    'detection' => [
        // Rendered next to the detected software/version chips on every toolkit page.
        'title_note' => 'Detected from the files',
    ],

    'failures' => [
        'gateway_down' => 'The node could not be reached, so detection failed this time.',
        'not_minecraft' => 'This server does not look like a Minecraft server yet — install a server software first.',
        // Properties page: proxies (Velocity, Waterfall, BungeeCord) never read server.properties.
        'proxy_no_properties' => 'This server runs a proxy, which does not use server.properties — these settings have no effect here.',
    ],
];
