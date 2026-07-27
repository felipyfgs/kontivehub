<?php

namespace App\Support;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Models\Tenant;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use InvalidArgumentException;

/**
 * Helper type-safe para feature flags do KontiveHub.
 *
 * Ordem de precedência (mais restritiva vence):
 * 1. kill_switch global
 * 2. global_enabled
 * 3. módulo enabled
 * 4. allowlist / allow_all_tenants (quando tenantId informado)
 * 5. para mutações: mutating.kill_switch, mutating.enabled, module.mutating_enabled
 *
 * Defaults de config: tudo OFF. Nunca hardcode enable=true neste helper.
 */
final class FeatureFlags
{
    /** @var list<string> */
    public const MODULES = [
        'simples_mei',
        'dctfweb',
        'installments',
        'sitfis',
        'mailbox',
        'declarations',
        'guides',
        'fgts',
        'registrations',
        'tax_processes',
    ];

    public static function isKillSwitchActive(): bool
    {
        return (bool) config('fiscal.kill_switch', false);
    }

    public static function isGloballyEnabled(): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        return true;
    }

    /**
     * Seleção privilegiada de tenant por PLATFORM_ADMIN (default OFF; kill switch vence).
     */
    public static function isPlatformPrivilegedContextEnabled(): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        return (bool) config('features.platform_privileged_context.enabled', false);
    }

    /**
     * Configuração unificada do escritório (perfil + certificado + consentimento).
     * Default OFF; kill switch vence; allowlist vazia exige allow_all_tenants.
     */
    public static function isUnifiedTenantConfigEnabled(?int $tenantId = null): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config('features.unified_tenant_config.enabled', false)) {
            return false;
        }

        if ($tenantId === null) {
            return true;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('features.unified_tenant_config.tenant_allowlist', []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return (bool) config('features.unified_tenant_config.allow_all_tenants', false);
        }

        return in_array($tenantId, $allowlist, true);
    }

    /**
     * Onboarding simplificado de produção SERPRO.
     * Default OFF; allowlist vazia não libera ninguém.
     */
    public static function isSerproProductionOnboardingEnabled(?int $tenantId = null): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config('features.serpro_production_onboarding.enabled', false)) {
            return false;
        }

        if ($tenantId === null) {
            return true;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('features.serpro_production_onboarding.tenant_allowlist', []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return (bool) config('features.serpro_production_onboarding.allow_all_tenants', false);
        }

        return in_array($tenantId, $allowlist, true);
    }

    /**
     * @return list<string>
     */
    public static function knownModules(): array
    {
        return self::MODULES;
    }

    public static function assertKnownModule(string $module): void
    {
        if (! in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException("Módulo de feature desconhecido: {$module}");
        }
    }

    /**
     * Módulo habilitado globalmente (e para o tenant, se tenantId informado).
     */
    public static function isModuleEnabled(string $module, ?int $tenantId = null): bool
    {
        $canonical = FiscalControlModule::fromRuntimeKey($module);
        $tenant = $tenantId === null
            ? null
            : Tenant::query()->withoutGlobalScopes()->find($tenantId);

        return app(FiscalModuleAvailabilityService::class)
            ->resolve($canonical, $tenant, FiscalOperationClass::Read)
            ->allowed;
    }

    /**
     * Operação mutante permitida para o módulo (e tenant opcional).
     */
    public static function isMutatingEnabled(string $module, ?int $tenantId = null): bool
    {
        if (self::isKillSwitchActive()
            || (bool) config('features.mutating.kill_switch', false)
            || ! (bool) config('features.global_enabled', false)
            || ! (bool) config('features.mutating.enabled', false)
        ) {
            return false;
        }

        self::assertKnownModule($module);
        if (! (bool) config("features.modules.{$module}.enabled", false)
            || ! (bool) config("features.modules.{$module}.mutating_enabled", false)
        ) {
            return false;
        }

        if ($tenantId === null) {
            return true;
        }

        $allowlist = config("features.modules.{$module}.tenant_allowlist", []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        return $allowlist === []
            ? (bool) config("features.modules.{$module}.allow_all_tenants", false)
            : in_array($tenantId, $allowlist, true);
    }

    public static function isTenantAllowedForModule(string $module, int $tenantId): bool
    {
        return self::isModuleEnabled($module, $tenantId);
    }

    /**
     * Snapshot sanitizado para ops/diagnóstico (sem segredos).
     *
     * @return array{
     *     kill_switch: bool,
     *     global_enabled: bool,
     *     platform_privileged_context: bool,
     *     unified_tenant_config: bool,
     *     mutating: array{enabled: bool, kill_switch: bool},
     *     modules: array<string, array{enabled: bool, mutating_enabled: bool, allow_all_tenants: bool, allowlist_count: int}>
     * }
     */
    public static function snapshot(): array
    {
        $modules = [];
        foreach (self::MODULES as $module) {
            /** @var list<int>|mixed $allowlist */
            $allowlist = config("features.modules.{$module}.tenant_allowlist", []);
            $count = is_array($allowlist) ? count($allowlist) : 0;
            $modules[$module] = [
                'enabled' => (bool) config("features.modules.{$module}.enabled", false),
                'mutating_enabled' => (bool) config("features.modules.{$module}.mutating_enabled", false),
                'allow_all_tenants' => (bool) config("features.modules.{$module}.allow_all_tenants", false),
                'allowlist_count' => $count,
            ];
        }

        return [
            'kill_switch' => self::isKillSwitchActive(),
            'global_enabled' => (bool) config('features.global_enabled', false),
            'platform_privileged_context' => self::isPlatformPrivilegedContextEnabled(),
            'unified_tenant_config' => self::isUnifiedTenantConfigEnabled(),
            'serpro_production_onboarding' => self::isSerproProductionOnboardingEnabled(),
            'mutating' => [
                'enabled' => (bool) config('features.mutating.enabled', false),
                'kill_switch' => (bool) config('features.mutating.kill_switch', false),
            ],
            'modules' => $modules,
        ];
    }
}
