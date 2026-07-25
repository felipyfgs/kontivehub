<?php

$deprecatedActivationKeys = [
    'FEATURES_KILL_SWITCH', 'FEATURES_GLOBAL_ENABLED', 'FEATURES_MUTATING_ENABLED',
    'FEATURE_SIMPLES_MEI_ENABLED', 'FEATURE_DCTFWEB_MIT_ENABLED',
    'FEATURE_PARCELAMENTOS_ENABLED', 'FEATURE_SITFIS_ENABLED',
    'FEATURE_MAILBOX_ENABLED', 'FEATURE_DECLARACOES_ENABLED',
    'FEATURE_GUIAS_ENABLED', 'FEATURE_FGTS_ENABLED', 'FEATURE_MUTACOES_ENABLED',
    'SERPRO_CAPABILITY_DEFAULT', 'SERPRO_CAPABILITY_SITFIS',
    'SERPRO_CAPABILITY_AUTENTICA_PROCURADOR', 'SERPRO_CAPABILITY_AUTHORIZATION',
    'SERPRO_CAPABILITY_MAILBOX', 'SERPRO_CAPABILITY_DCTFWEB',
    'SERPRO_CAPABILITY_SIMPLES_MEI', 'SERPRO_CAPABILITY_INSTALLMENTS',
    'SERPRO_CAPABILITY_GUIDES', 'SERPRO_CAPABILITY_REGISTRATIONS',
    'SERPRO_CAPABILITY_TAX_PROCESSES',
];

return [
    /* Únicos controles operacionais de ambiente da disponibilidade fiscal. */
    'profile' => strtolower((string) env('FISCAL_PROFILE', 'dev')),
    'kill_switch' => filter_var(env('FISCAL_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
    'deprecated_activation_keys_present' => array_values(array_filter(
        $deprecatedActivationKeys,
        static fn (string $key): bool => env($key) !== null,
    )),

    'procuracao' => [
        'freshness_days' => 7,
        'alert_days' => [30, 7, 1],
        'timezone' => 'America/Sao_Paulo',
    ],
];
