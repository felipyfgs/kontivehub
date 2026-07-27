<?php

$canonicalOrigins = [
    'https://app.kontivehub.com.br',
    'https://portal.kontivehub.com.br',
];

$requestedOrigins = array_values(array_filter(array_map(
    static fn (string $origin): string => trim($origin),
    explode(',', (string) env('CORS_ALLOWED_ORIGINS', implode(',', $canonicalOrigins))),
)));

$allowedOrigins = in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)
    ? array_values(array_filter(
        $requestedOrigins,
        static fn (string $origin): bool => $origin !== '*',
    ))
    : array_values(array_intersect($requestedOrigins, $canonicalOrigins));

return [
    'paths' => [
        'api/*',
        'sanctum/csrf-cookie',
        'login',
        'logout',
        'forgot-password',
        'reset-password',
        'user/*',
        'email/*',
        'broadcasting/auth',
    ],
    'allowed_methods' => ['*'],
    'allowed_origins' => $allowedOrigins,
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['*'],
    'exposed_headers' => [],
    'max_age' => 0,
    'supports_credentials' => true,
];
