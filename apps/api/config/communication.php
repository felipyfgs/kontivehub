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
    'profile_pictures' => [
        'max_bytes' => min(2_097_152, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_MAX_BYTES', 2_097_152))),
        'max_dimension' => min(4_096, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_MAX_DIMENSION', 4_096))),
        'connect_timeout_seconds' => min(10, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_CONNECT_TIMEOUT_SECONDS', 5))),
        'timeout_seconds' => min(30, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_TIMEOUT_SECONDS', 15))),
        'gateway_timeout_seconds' => min(90, max(16, (int) env('COMMUNICATION_PROFILE_PICTURES_GATEWAY_TIMEOUT_SECONDS', 90))),
        'negative_ttl_seconds' => max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_NEGATIVE_TTL_SECONDS', 86_400)),
        'refresh_ttl_seconds' => max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_REFRESH_TTL_SECONDS', 86_400)),
        'batch_size' => min(100, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_BATCH_SIZE', 100))),
        'inbox_batch_size' => min(25, max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_INBOX_BATCH_SIZE', 25))),
        'stream_rate_limit_per_minute' => max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_STREAM_RATE_LIMIT_PER_MINUTE', 600)),
        'stream_ip_rate_limit_per_minute' => max(1, (int) env('COMMUNICATION_PROFILE_PICTURES_STREAM_IP_RATE_LIMIT_PER_MINUTE', 1_200)),
    ],
    'outbound_conversation' => [
        'enabled' => filter_var(env('COMMUNICATION_OUTBOUND_CONVERSATION_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('COMMUNICATION_OUTBOUND_CONVERSATION_KILL_SWITCH', true), FILTER_VALIDATE_BOOL),
        'allow_all_tenants' => filter_var(env('COMMUNICATION_OUTBOUND_CONVERSATION_ALLOW_ALL_TENANTS', false), FILTER_VALIDATE_BOOL),
        'allowed_tenant_ids' => array_values(array_filter(array_map('trim', explode(',', (string) env('COMMUNICATION_OUTBOUND_CONVERSATION_ALLOWED_TENANT_IDS', ''))))),
    ],
    'history_media_recovery' => [
        // Operação administrativa fail-closed; nunca é agendada automaticamente.
        'enabled' => filter_var(env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_KILL_SWITCH', true), FILTER_VALIDATE_BOOL),
        'max_batch' => max(1, (int) env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_MAX_BATCH', 25)),
        'session_limit' => max(1, (int) env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_SESSION_LIMIT', 25)),
        'backoff_seconds' => max(0, (int) env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_BACKOFF_SECONDS', 300)),
        'accepted_result_timeout_seconds' => max(1, (int) env('COMMUNICATION_HISTORY_MEDIA_RECOVERY_ACCEPTED_RESULT_TIMEOUT_SECONDS', 900)),
    ],
];
