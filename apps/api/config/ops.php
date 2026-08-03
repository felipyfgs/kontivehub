<?php

/**
 * Gates operacionais de produção (readiness, heartbeat, evidências).
 * Sem segredos e sem contexto de Tenant.
 */
return [
    /*
    |--------------------------------------------------------------------------
    | Heartbeat do scheduler
    |--------------------------------------------------------------------------
    | O comando ops:scheduler-heartbeat grava um timestamp; o readiness falha
    | se o valor estiver ausente ou tiver mais de 180 segundos. A janela é fixa
    | para permanecer idêntica ao healthcheck do scheduler no Docker Swarm.
    */
    'scheduler_heartbeat' => [
        'cache_key' => 'ops:scheduler:heartbeat',
        'max_age_seconds' => 180,
    ],

    /*
    |--------------------------------------------------------------------------
    | Release
    |--------------------------------------------------------------------------
    | Preenchido no runtime produtivo via RELEASE_SHA (compose/env).
    */
    'release_sha' => (string) env('RELEASE_SHA', ''),
];
