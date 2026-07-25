<?php

$canonicalOrigins = [
    'https://app.kontivehub.com.br',
    'https://portal.kontivehub.com.br',
];

$requestedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('REVERB_ALLOWED_ORIGINS', implode(',', $canonicalOrigins))),
)));

$allowedOrigins = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
    ? array_values(array_filter(
        $requestedOrigins,
        static fn (string $origin): bool => $origin !== '*',
    ))
    : array_values(array_intersect($requestedOrigins, $canonicalOrigins));

return [
    'default' => env('REVERB_SERVER', 'reverb'),

    'servers' => [
        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => env('REVERB_SERVER_PORT', 8081),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => ['tls' => []],
            'max_request_size' => env('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => env('REVERB_SCALING_ENABLED', true),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', 'redis'),
                    'port' => env('REDIS_PORT', 6379),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', 0),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => env('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => env('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],
    ],

    'apps' => [
        'provider' => 'config',
        'apps' => [[
            'key' => env('REVERB_APP_KEY', 'communication-disabled'),
            'secret' => env('REVERB_APP_SECRET', 'communication-disabled'),
            'app_id' => env('REVERB_APP_ID', 'communication-disabled'),
            'options' => [
                'host' => env('REVERB_HOST', 'reverb'),
                'port' => env('REVERB_PORT', 8081),
                'scheme' => env('REVERB_SCHEME', 'http'),
                'useTLS' => env('REVERB_SCHEME', 'http') === 'https',
            ],
            'allowed_origins' => $allowedOrigins,
            'ping_interval' => env('REVERB_APP_PING_INTERVAL', 60),
            'activity_timeout' => env('REVERB_APP_ACTIVITY_TIMEOUT', 30),
            'max_connections' => env('REVERB_APP_MAX_CONNECTIONS'),
            'max_message_size' => env('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
        ]],
    ],
];
