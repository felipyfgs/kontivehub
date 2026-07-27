<?php

return [
    /* Únicos controles operacionais de ambiente da disponibilidade fiscal. */
    'profile' => strtolower((string) env('FISCAL_PROFILE', 'dev')),
    'kill_switch' => filter_var(env('FISCAL_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
    'procuracao' => [
        'freshness_days' => 7,
        'alert_days' => [30, 7, 1],
        'timezone' => 'America/Sao_Paulo',
    ],
];
