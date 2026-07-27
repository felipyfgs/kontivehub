<?php

namespace App\Services\Fiscal\Demo;

use App\Enums\FiscalDataOrigin;
use App\Models\Tenant;

/**
 * Resolve proveniência DEMO/SIMULATED/LIVE com guard de ambiente.
 * Em production NUNCA retorna DEMO, mesmo com tenant slug demo.
 */
final class FiscalDataOriginResolver
{
    public function __construct(
        private readonly DemoEnvironmentGuard $guard,
    ) {}

    public function resolve(?Tenant $tenant, bool $recordIsDemoFixture = false): FiscalDataOrigin
    {
        if (! $this->guard->isAllowedEnvironment()) {
            return FiscalDataOrigin::Live;
        }

        if ($tenant !== null && $this->guard->isDemoTenant($tenant) && $recordIsDemoFixture) {
            return FiscalDataOrigin::Demo;
        }

        if ($tenant !== null && $this->guard->isDemoTenant($tenant)) {
            // Tenant demo sem marcação explícita ainda é sintético em local/testing.
            return FiscalDataOrigin::Demo;
        }

        if ($recordIsDemoFixture) {
            return FiscalDataOrigin::Simulated;
        }

        return FiscalDataOrigin::Live;
    }

    /**
     * @return array{origin: string, label: string, synthetic: bool, banner: string|null, manifest_version: string|null}
     */
    public function toPublicMeta(?Tenant $tenant, bool $recordIsDemoFixture = false): array
    {
        $origin = $this->resolve($tenant, $recordIsDemoFixture);
        $base = $origin->toPublicArray();
        $base['manifest_version'] = $origin === FiscalDataOrigin::Demo
            ? $this->guard->manifestVersion()
            : null;

        return $base;
    }

    public function isDemoTenantContext(?Tenant $tenant): bool
    {
        return $tenant !== null
            && $this->guard->isAllowedEnvironment()
            && $this->guard->isDemoTenant($tenant);
    }
}
