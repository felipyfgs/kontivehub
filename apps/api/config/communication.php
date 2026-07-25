<?php

return [
    'enabled' => filter_var(env('COMMUNICATION_ENABLED', false), FILTER_VALIDATE_BOOL),
    'gateway' => [
        'enabled' => filter_var(env('WAZYNC_ENABLED', false), FILTER_VALIDATE_BOOL),
        'base_url' => env('WAZYNC_URL', 'http://wazync:8080'),
        'timeout_seconds' => (int) env('WAZYNC_TIMEOUT_SECONDS', 10),
    ],
    'flows' => [
        // Fail-closed: modelagem/publish/enable de fluxos permanece OFF por default.
        'enabled' => filter_var(env('COMMUNICATION_FLOWS_ENABLED', false), FILTER_VALIDATE_BOOL),
        // Fail-closed: runtime Horizon (correlação/execução) exige ON explícito.
        'runtime_enabled' => filter_var(env('COMMUNICATION_FLOWS_RUNTIME_ENABLED', false), FILTER_VALIDATE_BOOL),
        'delay_max_seconds' => (int) env('COMMUNICATION_FLOWS_DELAY_MAX_SECONDS', 86_400),
        'question_timeout_seconds' => (int) env('COMMUNICATION_FLOWS_QUESTION_TIMEOUT_SECONDS', 3_600),
    ],
    'hmac' => [
        'current_key_id' => env('WAZYNC_HMAC_KEY_ID', ''),
        'current_secret' => env('WAZYNC_HMAC_SECRET', ''),
        'previous_key_id' => env('WAZYNC_HMAC_PREVIOUS_KEY_ID', ''),
        'previous_secret' => env('WAZYNC_HMAC_PREVIOUS_SECRET', ''),
        'window_seconds' => (int) env('WAZYNC_HMAC_WINDOW_SECONDS', 300),
        'nonce_ttl_seconds' => (int) env('WAZYNC_HMAC_NONCE_TTL_SECONDS', 600),
    ],
    'media' => [
        'max_bytes' => (int) env('COMMUNICATION_MEDIA_MAX_BYTES', 20_971_520),
        'disk_root' => env('COMMUNICATION_MEDIA_DISK_ROOT', '/var/vault/communication'),
    ],
];
