<?php

/**
 * Gates operacionais de produção (readiness, heartbeat, evidências).
 * Sem segredos e sem contexto de Office.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Heartbeat do scheduler
    |--------------------------------------------------------------------------
    | O comando ops:scheduler-heartbeat grava um timestamp; o readiness falha
    | se o valor estiver ausente ou mais antigo que a janela abaixo.
    */
    'scheduler_heartbeat' => [
        'cache_key' => 'ops:scheduler:heartbeat',
        'max_age_seconds' => (int) env('OPS_SCHEDULER_HEARTBEAT_MAX_AGE', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    | Preenchido no runtime produtivo via RELEASE_SHA (compose/env).
    */
    'release_sha' => (string) env('RELEASE_SHA', ''),
];
