<?php

return [
    'statuses' => [
        'stopped_required' => 'The server must be offline to do this.',
        'wings_502' => 'The node did not answer properly — make sure the server can run commands right now.',
        'download_failed' => 'The download could not be completed. Try again in a moment.',
        'unpack_failed' => 'The archive could not be unpacked by the node.',
        'permission_denied' => 'You do not have permission to do this on this server.',
        'rate_limited' => 'Too many requests — wait a moment before trying again.',

        /*
         * Additive keys beyond the six in the frozen contract list; both are consumed by
         * McToolkit\Http\Controllers\* only.
         */
        'link_expired' => 'This link has expired. Open the page again and retry the action.',
        'service_unavailable' => 'The map renderer is not available yet on this panel installation.',
    ],
];
