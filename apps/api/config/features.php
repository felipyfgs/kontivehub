<?php

use App\Support\FeatureFlags;
use App\Support\PlatformPrivilegedContext;

/**
 * Feature flags do hub de monitoramento fiscal (SaaS multi-escritório).
 *
 * TODAS as flags começam desabilitadas. Kill switch global vence qualquer enable.
 * Overrides por tenant usam allowlist (vazia = ninguém, salvo allow_all_tenants).
 *
 * @see FeatureFlags
 * @see openspec/changes/build-complete-fiscal-monitoring-hub
 */
$parseIdList = static function (?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part !== '' && ctype_digit($part)) {
            $ids[] = (int) $part;
        }
    }

    return array_values(array_unique($ids));
};

$moduleDefaults = static function (string $envPrefix) use ($parseIdList): array {
    return [
        'enabled' => filter_var(env("{$envPrefix}_ENABLED", false), FILTER_VALIDATE_BOOL),
        'mutating_enabled' => filter_var(env("{$envPrefix}_MUTATING_ENABLED", false), FILTER_VALIDATE_BOOL),
        'tenant_allowlist' => $parseIdList(env("{$envPrefix}_TENANT_ALLOWLIST")),
        'allow_all_tenants' => filter_var(env("{$envPrefix}_ALLOW_ALL_TENANTS", false), FILTER_VALIDATE_BOOL),
    ];
};

return [
    /**
     * Kill switch global: força todos os módulos e mutações OFF.
     * Preferir a kill switch a desligar flags individuais em incidente.
     */
    'kill_switch' => filter_var(env('FEATURES_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),

    /**
     * Master switch do KontiveHub. Mesmo com módulo enabled, global_enabled=false bloqueia.
     */
    'global_enabled' => filter_var(env('FEATURES_GLOBAL_ENABLED', false), FILTER_VALIDATE_BOOL),

    /**
     * Operações mutantes (transmissão, emissão, adesão, etc.) — gate transversal.
     * Cada módulo ainda exige sua própria mutating_enabled.
     */
    'mutating' => [
        'enabled' => filter_var(env('FEATURES_MUTATING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'kill_switch' => filter_var(env('FEATURES_MUTATING_KILL_SWITCH', false), FILTER_VALIDATE_BOOL),
    ],

    /**
     * Contexto privilegiado PLATFORM_ADMIN (seletor global de tenant, sem membership fictícia).
     * Default OFF até aprovação jurídica/segurança e plano de rollout.
     *
     * @see PlatformPrivilegedContext
     */
    'platform_privileged_context' => [
        'enabled' => filter_var(env('PLATFORM_PRIVILEGED_CONTEXT', false), FILTER_VALIDATE_BOOL),
    ],

    /**
     * Configuração unificada do escritório (perfil institucional, certificado, consentimento).
     * Default OFF — rollout por coorte via allowlist.
     */
    'unified_tenant_config' => [
        'enabled' => filter_var(env('FEATURE_UNIFIED_TENANT_CONFIG_ENABLED', false), FILTER_VALIDATE_BOOL),
        'tenant_allowlist' => $parseIdList(env('FEATURE_UNIFIED_TENANT_CONFIG_TENANT_ALLOWLIST')),
        'allow_all_tenants' => filter_var(env('FEATURE_UNIFIED_TENANT_CONFIG_ALLOW_ALL_TENANTS', false), FILTER_VALIDATE_BOOL),
    ],

    /**
     * Onboarding simplificado de ativação SERPRO em produção.
     * Default OFF; allowlist vazia = ninguém, salvo allow_all_tenants=true.
     */
    'serpro_production_onboarding' => [
        'enabled' => filter_var(env('FEATURE_SERPRO_PRODUCTION_ONBOARDING_ENABLED', false), FILTER_VALIDATE_BOOL),
        'tenant_allowlist' => $parseIdList(env('FEATURE_SERPRO_PRODUCTION_ONBOARDING_TENANT_ALLOWLIST')),
        'allow_all_tenants' => filter_var(env('FEATURE_SERPRO_PRODUCTION_ONBOARDING_ALLOW_ALL_TENANTS', false), FILTER_VALIDATE_BOOL),
    ],

    /** Módulos fiscais canônicos. */
    'modules' => [
        'simples_mei' => $moduleDefaults('FEATURE_SIMPLES_MEI'),
        'dctfweb' => $moduleDefaults('FEATURE_DCTFWEB_MIT'),
        'installments' => $moduleDefaults('FEATURE_PARCELAMENTOS'),
        'sitfis' => $moduleDefaults('FEATURE_SITFIS'),
        'mailbox' => $moduleDefaults('FEATURE_MAILBOX'),
        'declarations' => $moduleDefaults('FEATURE_DECLARACOES'),
        'guides' => $moduleDefaults('FEATURE_GUIAS'),
        'fgts' => $moduleDefaults('FEATURE_FGTS'),
        'registrations' => $moduleDefaults('FEATURE_REGISTRATIONS'),
        'tax_processes' => $moduleDefaults('FEATURE_TAX_PROCESSES'),
    ],
];
