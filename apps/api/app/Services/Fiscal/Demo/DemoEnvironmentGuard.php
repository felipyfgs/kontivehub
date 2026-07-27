<?php

namespace App\Services\Fiscal\Demo;

use App\Models\Tenant;
use LogicException;
use RuntimeException;

/**
 * Guard de ambiente/tenant para fixtures fiscais demonstrativas.
 * Validação MUST ocorrer antes de qualquer mutação no banco.
 */
final class DemoEnvironmentGuard
{
    /**
     * @throws LogicException|RuntimeException
     */
    public function assertCanSeed(?Tenant $tenant = null): Tenant
    {
        if (! $this->isAllowedEnvironment()) {
            throw new LogicException(
                'FiscalMonitoringDemoSeeder recusado: ambiente "'.app()->environment()
                .'" não permite fixtures demonstrativas (somente local/testing).'
            );
        }

        if (! (bool) config('fiscal_demo.enabled', true)) {
            throw new LogicException(
                'FiscalMonitoringDemoSeeder recusado: fiscal_demo.enabled=false.'
            );
        }

        $slug = $this->demoTenantSlug();
        $resolved = $tenant ?? Tenant::query()->where('slug', $slug)->first();

        if ($resolved === null) {
            throw new RuntimeException(
                "Tenant demo com slug \"{$slug}\" não encontrado. Execute o DatabaseSeeder base antes."
            );
        }

        if ($resolved->slug !== $slug) {
            throw new LogicException(
                'FiscalMonitoringDemoSeeder recusado: tenant "'.$resolved->slug
                ."\" não é o tenant demo configurado (\"{$slug}\")."
            );
        }

        return $resolved;
    }

    public function isAllowedEnvironment(): bool
    {
        $allowed = config('fiscal_demo.allowed_environments', ['local', 'testing']);

        return app()->environment(is_array($allowed) ? $allowed : ['local', 'testing']);
    }

    public function isDemoTenant(Tenant $tenant): bool
    {
        return $tenant->slug === $this->demoTenantSlug();
    }

    public function isProductionLike(): bool
    {
        return app()->environment('production', 'prod', 'staging');
    }

    /**
     * Bloqueia se variável demo estiver ligada em produção (não habilita seeder/origem).
     */
    public function assertDemoVarsHarmlessInProduction(): void
    {
        if (! $this->isProductionLike()) {
            return;
        }

        // Em produção o guard prevalece: seeder e DEMO origin nunca ativam.
        // Este método documenta a invariante e serve a testes de configuração.
        if ((bool) config('fiscal_demo.enabled', false) || env('FISCAL_DEMO_ENABLED') === true) {
            // Não lança em runtime de request — apenas registra falha sanitizada em log se chamado.
            logger()->warning('fiscal_demo: variável DEMO presente em ambiente produtivo; ignorada pelo guard.');
        }
    }

    public function demoTenantSlug(): string
    {
        return (string) config('fiscal_demo.tenant_slug', 'demo');
    }

    public function sentinelTenantSlug(): string
    {
        return (string) config('fiscal_demo.sentinel_tenant_slug', 'demo-sentinel');
    }

    public function fixtureMarker(): string
    {
        return (string) config('fiscal_demo.fixture_marker', '[demo-fixture]');
    }

    public function correlationPrefix(): string
    {
        return (string) config('fiscal_demo.correlation_prefix', 'DEMO_');
    }

    public function watermark(): string
    {
        return (string) config('fiscal_demo.watermark', 'DEMONSTRAÇÃO — SEM VALIDADE FISCAL');
    }

    public function manifestVersion(): string
    {
        return (string) config('fiscal_demo.manifest_version', '1.0.0');
    }
}
