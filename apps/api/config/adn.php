<?php

return [
    // Empty ADN_BASE_URL= in .env must not wipe the official default (JSON contribuintes API).
    'base_url' => env('ADN_BASE_URL') ?: 'https://adn.nfse.gov.br/contribuintes',
    'environment' => env('ADN_ENVIRONMENT', 'restricted_production'),
    'timeout_seconds' => (int) env('ADN_TIMEOUT_SECONDS', 30),
    'connect_timeout_seconds' => (int) env('ADN_CONNECT_TIMEOUT_SECONDS', 10),
    'max_concurrent' => (int) env('ADN_MAX_CONCURRENT', 4),
    'rate_limit_rps' => (float) env('ADN_RATE_LIMIT_RPS', 4),
    'max_pages_per_job' => (int) env('ADN_MAX_PAGES_PER_JOB', 20),
    'decode_failure_threshold' => (int) env('ADN_DECODE_FAILURE_THRESHOLD', 5),
    'job_timeout_seconds' => (int) env('ADN_JOB_TIMEOUT_SECONDS', 900),
    'lock_ttl_seconds' => (int) env('ADN_LOCK_TTL_SECONDS', 960),
    'stale_lease_seconds' => (int) env('ADN_STALE_LEASE_SECONDS', 1020),
    'verify_tls' => filter_var(env('ADN_VERIFY_TLS', true), FILTER_VALIDATE_BOOL),
    'min_tls_version' => CURL_SSLVERSION_TLSv1_2,
];
