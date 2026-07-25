<?php

$parseIdList = static function (?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    return array_values(array_unique(array_map(
        'intval',
        array_filter(array_map('trim', explode(',', $raw)), 'ctype_digit'),
    )));
};

return [
    'enabled' => filter_var(env('MEI_AUTOMATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'kill_switch' => filter_var(env('MEI_AUTOMATION_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
    'live_egress_enabled' => filter_var(env('MEI_AUTOMATION_LIVE_EGRESS_ENABLED', false), FILTER_VALIDATE_BOOL),
    'fixture_enabled' => filter_var(env('MEI_AUTOMATION_FIXTURE_ENABLED', false), FILTER_VALIDATE_BOOL),
    'office_allowlist' => $parseIdList(env('MEI_AUTOMATION_OFFICE_ALLOWLIST')),
    'allow_all_offices' => filter_var(env('MEI_AUTOMATION_ALLOW_ALL_OFFICES', false), FILTER_VALIDATE_BOOL),
    'base_url' => env('MEI_AUTOMATION_URL', 'http://mei:8080'),
    'timeout_seconds' => (int) env('MEI_AUTOMATION_HTTP_TIMEOUT', 15),
    'poll_interval_seconds' => (int) env('MEI_AUTOMATION_POLL_INTERVAL', 10),
    'result_ttl_seconds' => (int) env('MEI_AUTOMATION_RESULT_TTL_SECONDS', 900),
    'queue' => env('MEI_AUTOMATION_QUEUE', 'fiscal'),
    'artifact_max_bytes' => (int) env('MEI_AUTOMATION_ARTIFACT_MAX_BYTES', 10485760),
    'artifact_allowed_content_types' => [
        'application/pdf',
        'application/json',
        'text/plain',
    ],
    'hmac' => [
        'key_id' => env('MEI_AUTOMATION_HMAC_KEY_ID', 'laravel'),
        'secret' => env('MEI_AUTOMATION_HMAC_SECRET'),
        'max_clock_skew_seconds' => (int) env('MEI_AUTOMATION_HMAC_MAX_CLOCK_SKEW', 60),
    ],
    'provider_policy' => [
        'default' => env('MEI_AUTOMATION_PROVIDER_DEFAULT', 'serpro'),
        'operations' => [
            'pgmei.gerardaspdf' => env('MEI_AUTOMATION_PROVIDER_PGMEI_DAS_PDF'),
            'pgmei.gerardascodbarra' => env('MEI_AUTOMATION_PROVIDER_PGMEI_DAS_BARCODE'),
            'pgmei.dividaativa' => env('MEI_AUTOMATION_PROVIDER_PGMEI_DEBT'),
            'dasnsimei.consultimadecrec' => env('MEI_AUTOMATION_PROVIDER_DASN_HISTORY'),
        ],
    ],
    'captcha' => [
        'driver' => env('MEI_AUTOMATION_CAPTCHA_DRIVER', 'manual'),
        'budget_micros' => (int) env('MEI_AUTOMATION_CAPTCHA_BUDGET_MICROS', 0),
        'nopecha_enabled' => filter_var(env('MEI_AUTOMATION_NOPECHA_ENABLED', false), FILTER_VALIDATE_BOOL),
        'nopecha_operation_allowlist' => array_values(array_filter(array_map(
            static fn (string $operation): string => strtolower(trim($operation)),
            explode(',', (string) env('MEI_AUTOMATION_NOPECHA_OPERATION_ALLOWLIST', '')),
        ))),
        'nopecha_unit_cost_micros' => (int) env('MEI_AUTOMATION_NOPECHA_UNIT_COST_MICROS', 0),
    ],
];
