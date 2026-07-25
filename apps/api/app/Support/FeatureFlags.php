<?php

namespace App\Support;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Models\Office;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use InvalidArgumentException;

/**
 * Helper type-safe para feature flags do KontiveHub.
 *
 * Ordem de precedência (mais restritiva vence):
 * 1. kill_switch global
 * 2. global_enabled
 * 3. módulo enabled
 * 4. allowlist / allow_all_offices (quando officeId informado)
 * 5. para mutações: mutating.kill_switch, mutating.enabled, module.mutating_enabled
 *
 * Defaults de config: tudo OFF. Nunca hardcode enable=true neste helper.
 */
final class FeatureFlags
{
    /** @var list<string> */
    public const MODULES = [
        'simples_mei',
        'dctfweb_mit',
        'parcelamentos',
        'sitfis',
        'mailbox',
        'declaracoes',
        'guias',
        'fgts',
        'mutacoes',
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
     * RBAC multi-tenant canônico (TenantPermission / TenantRole).
     * Default OFF; kill switch global vence. Com OFF, autoridade permanece legada.
     */
    public static function isCanonicalMultitenantRbacEnabled(): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        return (bool) config('features.canonical_multitenant_rbac.enabled', false);
    }

    /**
     * Configuração unificada do escritório (perfil + A1 canônico + consentimento).
     * Default OFF; kill switch vence; allowlist vazia exige allow_all_offices.
     */
    public static function isUnifiedOfficeConfigEnabled(?int $officeId = null): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config('features.unified_office_config.enabled', false)) {
            return false;
        }

        if ($officeId === null) {
            return true;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('features.unified_office_config.office_allowlist', []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return (bool) config('features.unified_office_config.allow_all_offices', false);
        }

        return in_array($officeId, $allowlist, true);
    }

    /**
     * Onboarding simplificado de produção SERPRO.
     * Default OFF; allowlist vazia não libera ninguém.
     */
    public static function isSerproProductionOnboardingEnabled(?int $officeId = null): bool
    {
        if (self::isKillSwitchActive()) {
            return false;
        }

        if (! (bool) config('features.serpro_production_onboarding.enabled', false)) {
            return false;
        }

        if ($officeId === null) {
            return true;
        }

        /** @var list<int> $allowlist */
        $allowlist = config('features.serpro_production_onboarding.office_allowlist', []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        if ($allowlist === []) {
            return (bool) config('features.serpro_production_onboarding.allow_all_offices', false);
        }

        return in_array($officeId, $allowlist, true);
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
     * Módulo habilitado globalmente (e para o tenant, se officeId informado).
     */
    public static function isModuleEnabled(string $module, ?int $officeId = null): bool
    {
        if ($module === 'mutacoes') {
            return false;
        }

        $canonical = FiscalControlModule::fromRuntimeKey($module);
        $office = $officeId === null
            ? null
            : Office::query()->withoutGlobalScopes()->find($officeId);

        return app(FiscalModuleAvailabilityService::class)
            ->resolve($canonical, $office, FiscalOperationClass::Read)
            ->allowed;
    }

    /**
     * Operação mutante permitida para o módulo (e tenant opcional).
     */
    public static function isMutatingEnabled(string $module, ?int $officeId = null): bool
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

        if ($officeId === null) {
            return true;
        }

        $allowlist = config("features.modules.{$module}.office_allowlist", []);
        if (! is_array($allowlist)) {
            $allowlist = [];
        }

        return $allowlist === []
            ? (bool) config("features.modules.{$module}.allow_all_offices", false)
            : in_array($officeId, $allowlist, true);
    }

    public static function isOfficeAllowedForModule(string $module, int $officeId): bool
    {
        return self::isModuleEnabled($module, $officeId);
    }

    /**
     * Snapshot sanitizado para ops/diagnóstico (sem segredos).
     *
     * @return array{
     *     kill_switch: bool,
     *     global_enabled: bool,
     *     platform_privileged_context: bool,
     *     canonical_multitenant_rbac: bool,
     *     unified_office_config: bool,
     *     mutating: array{enabled: bool, kill_switch: bool},
     *     modules: array<string, array{enabled: bool, mutating_enabled: bool, allow_all_offices: bool, allowlist_count: int}>
     * }
     */
    public static function snapshot(): array
    {
        $modules = [];
        foreach (self::MODULES as $module) {
            /** @var list<int>|mixed $allowlist */
            $allowlist = config("features.modules.{$module}.office_allowlist", []);
            $count = is_array($allowlist) ? count($allowlist) : 0;
            $modules[$module] = [
                'enabled' => (bool) config("features.modules.{$module}.enabled", false),
                'mutating_enabled' => (bool) config("features.modules.{$module}.mutating_enabled", false),
                'allow_all_offices' => (bool) config("features.modules.{$module}.allow_all_offices", false),
                'allowlist_count' => $count,
            ];
        }

        return [
            'kill_switch' => self::isKillSwitchActive(),
            'global_enabled' => (bool) config('features.global_enabled', false),
            'platform_privileged_context' => self::isPlatformPrivilegedContextEnabled(),
            'canonical_multitenant_rbac' => self::isCanonicalMultitenantRbacEnabled(),
            'unified_office_config' => self::isUnifiedOfficeConfigEnabled(),
            'serpro_production_onboarding' => self::isSerproProductionOnboardingEnabled(),
            'mutating' => [
                'enabled' => (bool) config('features.mutating.enabled', false),
                'kill_switch' => (bool) config('features.mutating.kill_switch', false),
            ],
            'modules' => $modules,
        ];
    }
}
