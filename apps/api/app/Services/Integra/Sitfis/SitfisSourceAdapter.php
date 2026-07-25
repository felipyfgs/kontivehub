<?php

namespace App\Services\Integra\Sitfis;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;

/**
 * Adapter fiscal do módulo SITFIS (read-only, cobertura FULL quando fonte responde).
 */
final class SitfisSourceAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly SitfisFlowService $flow,
    ) {}

    public function systemCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS');
    }

    public function serviceCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS');
    }

    public function operationCode(): string
    {
        return (string) config('fiscal_monitoring.sitfis.operation_code', 'MONITOR');
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Full;
    }

    public function moduleKey(): ?string
    {
        return 'sitfis';
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return strcasecmp($request->systemCode, $this->systemCode()) === 0
            && strcasecmp($request->serviceCode, $this->serviceCode()) === 0;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        return $this->flow->execute($request);
    }
}
