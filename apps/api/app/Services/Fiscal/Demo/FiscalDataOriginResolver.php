<?php

namespace App\Services\Fiscal\Demo;

use App\Enums\FiscalDataOrigin;
use App\Models\Office;

/**
 * Resolve proveniência DEMO/SIMULATED/LIVE com guard de ambiente.
 * Em production NUNCA retorna DEMO, mesmo com office slug demo.
 */
final class FiscalDataOriginResolver
{
    public function __construct(
        private readonly DemoEnvironmentGuard $guard,
    ) {}

    public function resolve(?Office $office, bool $recordIsDemoFixture = false): FiscalDataOrigin
    {
        if (! $this->guard->isAllowedEnvironment()) {
            return FiscalDataOrigin::Live;
        }

        if ($office !== null && $this->guard->isDemoOffice($office) && $recordIsDemoFixture) {
            return FiscalDataOrigin::Demo;
        }

        if ($office !== null && $this->guard->isDemoOffice($office)) {
            // Office demo sem marcação explícita ainda é sintético em local/testing.
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
    public function toPublicMeta(?Office $office, bool $recordIsDemoFixture = false): array
    {
        $origin = $this->resolve($office, $recordIsDemoFixture);
        $base = $origin->toPublicArray();
        $base['manifest_version'] = $origin === FiscalDataOrigin::Demo
            ? $this->guard->manifestVersion()
            : null;

        return $base;
    }

    public function isDemoOfficeContext(?Office $office): bool
    {
        return $office !== null
            && $this->guard->isAllowedEnvironment()
            && $this->guard->isDemoOffice($office);
    }
}
