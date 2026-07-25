<?php

use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use Database\Seeders\FiscalMonitoringDemoSeeder;

/**
 * Fixtures fiscais demonstrativas (office demo only, local/testing).
 *
 * NUNCA habilita seeder, origem sintética ou fallback em production —
 * o guard de ambiente prevalece sobre qualquer variável DEMO_*.
 *
 * @see DemoEnvironmentGuard
 * @see FiscalMonitoringDemoSeeder
 */

return [
    /**
     * Master switch opcional (ainda exige app env local/testing).
     * Em production o seeder sempre recusa, mesmo com enabled=true.
     */
    'enabled' => filter_var(env('FISCAL_DEMO_ENABLED', true), FILTER_VALIDATE_BOOL),

    /** Slug do tenant contábil principal (canário / ex-demo). */
    'office_slug' => env('FISCAL_DEMO_OFFICE_SLUG', 'contador'),

    /** Office sentinela de isolamento (mesmo CNPJ, nunca na carteira demo). */
    'sentinel_office_slug' => env('FISCAL_DEMO_SENTINEL_SLUG', 'plataforma'),

    /**
     * Data-âncora determinística (ISO-8601). Competências, vencimentos e
     * timestamps relativos derivam deste relógio — não de now() flutuante.
     */
    'anchor_at' => env('DEMO_FISCAL_ANCHOR_AT', '2026-06-15T12:00:00-03:00'),

    /** Versão lógica do manifesto (bump força recriação dos fixtures marcados). */
    'manifest_version' => env('FISCAL_DEMO_MANIFEST_VERSION', '1.0.0'),

    /** Prefixo estável de correlation_id / chaves sintéticas. */
    'correlation_prefix' => 'DEMO_',

    /** Marcador em notes / metadata para purga seletiva. */
    'fixture_marker' => '[demo-fixture]',

    /** Marca d'água obrigatória em evidências e downloads sintéticos. */
    'watermark' => 'DEMONSTRAÇÃO — SEM VALIDADE FISCAL',

    /**
     * Perfil local somente leitura: features de leitura ON, mutações/scheduler OFF.
     * Aplicado documentacionalmente; o seeder não altera .env automaticamente.
     */
    'read_only_profile' => [
        'features_global_enabled' => true,
        'fiscal_monitoring_enabled' => true,
        'mutating_enabled' => false,
        'scheduler_enabled' => false,
        'modules_read_enabled' => true,
        'modules_mutating_enabled' => false,
    ],

    /**
     * Ambientes onde o seeder e a origem DEMO são permitidos.
     * Production/staging nunca entram nesta lista.
     */
    'allowed_environments' => ['local', 'testing'],
];
