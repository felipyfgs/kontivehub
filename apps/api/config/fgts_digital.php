<?php

return [
    /*
    | Disabled é o único default seguro. Fixture exercita o contrato sem rede;
    | portal_browser exige opt-in separado para leitura e para mutação.
    */
    'driver' => env('FGTS_DIGITAL_DRIVER', 'disabled'),
    'contract_version' => 1,
    'egress_enabled' => (bool) env('FGTS_DIGITAL_EGRESS_ENABLED', false),
    'mutations_enabled' => (bool) env('FGTS_DIGITAL_MUTATIONS_ENABLED', false),
    'office_credential_enabled' => (bool) env('FGTS_DIGITAL_OFFICE_CREDENTIAL_ENABLED', false),
    'kill_switch' => (bool) env('FGTS_DIGITAL_KILL_SWITCH', false),

    'runtime' => [
        'executable' => env('FGTS_DIGITAL_PYTHON', '/opt/fgts-rpa/bin/python'),
        'worker' => env('FGTS_DIGITAL_WORKER', base_path('rpa/fgts_digital/worker.py')),
        'fixtures' => env('FGTS_DIGITAL_FIXTURES', base_path('rpa/fgts_digital/fixtures')),
        'timeout_seconds' => (int) env('FGTS_DIGITAL_TIMEOUT_SECONDS', 120),
        'max_output_bytes' => (int) env('FGTS_DIGITAL_MAX_OUTPUT_BYTES', 8_388_608),
        'playwright_version' => '1.61.0',
    ],

    /*
    | O solver roda dentro do mesmo processo Playwright para preservar URL,
    | cookies e user-agent. Proxy é opcional e, quando presente, é compartilhado
    | entre o Chromium e a Token API para manter a mesma saída de rede.
    */
    'captcha' => [
        'driver' => env('FGTS_DIGITAL_CAPTCHA_DRIVER', 'disabled'),
        'endpoint' => env('FGTS_DIGITAL_CAPTCHA_ENDPOINT', 'https://api.nopecha.com/token/'),
        'api_key' => env('FGTS_DIGITAL_NOPECHA_API_KEY'),
        'proxy_url' => env('FGTS_DIGITAL_PROXY_URL'),
        'timeout_seconds' => (int) env('FGTS_DIGITAL_CAPTCHA_TIMEOUT_SECONDS', 180),
        'poll_interval_milliseconds' => (int) env('FGTS_DIGITAL_CAPTCHA_POLL_INTERVAL_MILLISECONDS', 1_000),
        'max_attempts' => (int) env('FGTS_DIGITAL_CAPTCHA_MAX_ATTEMPTS', 1),
        'max_credits_per_run' => (int) env('FGTS_DIGITAL_CAPTCHA_MAX_CREDITS_PER_RUN', 5),
        'credits_per_attempt' => 5,
    ],

    'portal' => [
        'login_url' => env('FGTS_DIGITAL_LOGIN_URL', 'https://fgtsdigital.sistema.gov.br/portal/login'),
        'app_url' => env('FGTS_DIGITAL_APP_URL', 'https://fgtsdigital.sistema.gov.br/portal/'),
        'certificate_origins' => [
            'https://certificado.sso.acesso.gov.br',
            'https://sso.acesso.gov.br',
            'https://fgtsdigital.sistema.gov.br',
        ],
        'allowed_host_suffixes' => [
            '.gov.br',
            '.acesso.gov.br',
            '.caixa.gov.br',
            '.hcaptcha.com',
        ],
    ],

    'session' => [
        'ttl_minutes' => (int) env('FGTS_DIGITAL_SESSION_TTL_MINUTES', 30),
        'max_import_bytes' => (int) env('FGTS_DIGITAL_SESSION_MAX_IMPORT_BYTES', 1_048_576),
    ],

    'preview_ttl_seconds' => (int) env('FGTS_DIGITAL_PREVIEW_TTL_SECONDS', 300),
    'queue' => env('FGTS_DIGITAL_QUEUE', 'default'),
    'allowed_guide_types' => ['MONTHLY', 'TERMINATION', 'CONSIGNMENT', 'MIXED', 'PARAMETERIZED'],
    'scheduler' => [
        'enabled' => (bool) env('FGTS_DIGITAL_SCHEDULER_ENABLED', false),
        'emissions_enabled' => (bool) env('FGTS_DIGITAL_SCHEDULED_EMISSIONS_ENABLED', false),
        'max_dispatch_per_tick' => (int) env('FGTS_DIGITAL_SCHEDULER_MAX_DISPATCH_PER_TICK', 10),
        'max_amount_cents' => (int) env('FGTS_DIGITAL_SCHEDULED_MAX_AMOUNT_CENTS', 0),
    ],
];
