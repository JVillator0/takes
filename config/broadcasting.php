<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Broadcasting (spec 5, D-028)
|--------------------------------------------------------------------------
|
| Reverb in production; `log` locally and in tests. The client treats any
| connection it cannot open as "not connected" and polls instead, so no
| setting here switches the live UI on or off.
|
*/

return [

    'default' => env('BROADCAST_CONNECTION', 'null'),

    'connections' => [

        // Keys default to '' so the app boots (package:discover, routes/channels.php)
        // with BROADCAST_CONNECTION=reverb before the keys are injected.
        'reverb' => [
            'driver' => 'reverb',
            'key' => env('REVERB_APP_KEY', ''),
            'secret' => env('REVERB_APP_SECRET', ''),
            'app_id' => env('REVERB_APP_ID', ''),
            'options' => [
                'host' => env('REVERB_HOST'),
                'port' => env('REVERB_PORT', 443),
                'scheme' => env('REVERB_SCHEME', 'https'),
                'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
            ],
            // A slow or absent Reverb must never hold a queue worker (W-18).
            'client_options' => [
                'timeout' => 2,
                'connect_timeout' => 2,
            ],
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
