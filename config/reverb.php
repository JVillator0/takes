<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Reverb (spec 5, D-028)
|--------------------------------------------------------------------------
|
| `reverb:start` binds REVERB_SERVER_PORT; clients connect to REVERB_PORT.
| Each pool worktree sets both to its slot (8080 to 8085), so five servers
| never collide (.claude/worktree-pool.md). One server, no horizontal
| scaling: the app runs on one Laravel Cloud instance (D-038).
|
*/

return [

    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => 10_000,
            'scaling' => [
                'enabled' => false,
                'channel' => 'reverb',
                'server' => [],
            ],
            'pulse_ingest_interval' => 15,
            'telescope_ingest_interval' => 15,
        ],

    ],

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => env('REVERB_APP_KEY'),
                'secret' => env('REVERB_APP_SECRET'),
                'app_id' => env('REVERB_APP_ID'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME', 'https'),
                    'useTLS' => env('REVERB_SCHEME', 'https') === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => 60,
                'activity_timeout' => 30,
                'max_connections' => null,
                'max_message_size' => 10_000,
                'max_channels_per_connection' => 1000,
                'accept_client_events_from' => 'members',
                'rate_limiting' => [
                    'enabled' => false,
                    'max_attempts' => 60,
                    'decay_seconds' => 60,
                    'terminate_on_limit' => false,
                ],
            ],
        ],

    ],

];
