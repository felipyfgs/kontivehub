<?php

return [
    'path' => env('DEVELOPMENT_DATA_PATH', base_path('.local/dados')),
    'platform' => [
        'admin_email' => env('DEVELOPMENT_PLATFORM_ADMIN_EMAIL', 'platform@kontivehub.local'),
        'admin_password' => env('DEVELOPMENT_PLATFORM_ADMIN_PASSWORD', 'password'),
    ],
    'tenant' => [
        'admin_email' => env('DEVELOPMENT_TENANT_ADMIN_EMAIL', 'admin@kontivehub.local'),
        'admin_password' => env('DEVELOPMENT_TENANT_ADMIN_PASSWORD', 'password'),
    ],
];
