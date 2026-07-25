<?php

use App\Services\Work\Demo\WorkDemoEnvironmentGuard;
use Database\Seeders\OperationalWorkDemoSeeder;

/**
 * Fixtures operacionais demonstrativas (office demo, local/testing).
 *
 * NUNCA habilita seeder em production — o guard de ambiente prevalece
 * sobre qualquer variável DEMO_*.
 *
 * @see WorkDemoEnvironmentGuard
 * @see OperationalWorkDemoSeeder
 */

return [
    /** Master switch opcional (ainda exige app env local/testing). */
    'enabled' => filter_var(env('WORK_DEMO_ENABLED', true), FILTER_VALIDATE_BOOL),

    /** Ambientes permitidos. */
    'allowed_environments' => ['local', 'testing'],

    /** Slug do tenant contábil principal (usuários da sessão / ex-demo). */
    'office_slug' => env('WORK_DEMO_OFFICE_SLUG', 'contador'),

    /** Office sentinela de isolamento (mesmo CNPJ/rótulo, sem membership demo). */
    'sentinel_office_slug' => env('WORK_DEMO_SENTINEL_SLUG', 'plataforma'),

    /**
     * Data-âncora civil (Y-m-d). Prazos e competências relativas derivam dela.
     * Se ausente/ inválida: hoje civil no timezone do office.
     */
    'anchor_date' => env('DEMO_WORK_ANCHOR_DATE'),

    /** Prefixo estável de chaves lógicas no manifesto. */
    'key_prefix' => 'DEMO_WORK',

    /** Marcador em description/notes para restringir reconciliação. */
    'fixture_marker' => '[demo-work-fixture]',

    /** Aviso obrigatório em evidências sintéticas. */
    'watermark' => 'DEMONSTRAÇÃO — SEM VALIDADE FISCAL',

    /** Versão lógica do manifesto. */
    'manifest_version' => env('WORK_DEMO_MANIFEST_VERSION', '1.0.0'),
];
