<?php

namespace App\Services\FiscalMonitoring;

use App\Contracts\FiscalSourceAdapter;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;

/**
 * Adapter padrão: declara UNSUPPORTED sem inventar regularidade.
 * Módulos filhos registram adapters reais no container.
 */
final class NullFiscalSourceAdapter implements FiscalSourceAdapter
{
    public function __construct(
        private readonly string $systemCode = 'UNKNOWN',
        private readonly string $serviceCode = 'UNKNOWN',
        private readonly string $operationCode = 'MONITOR',
        private readonly ?string $moduleKey = null,
    ) {}

    public function systemCode(): string
    {
        return $this->systemCode;
    }

    public function serviceCode(): string
    {
        return $this->serviceCode;
    }

    public function operationCode(): string
    {
        return $this->operationCode;
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::ReadOnly;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Unsupported;
    }

    public function moduleKey(): ?string
    {
        return $this->moduleKey;
    }

    public function supports(FiscalAdapterRequest $request): bool
    {
        return true;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        return FiscalAdapterResult::unsupported(
            "Nenhum adapter registrado para {$request->systemCode}/{$request->serviceCode}/{$request->operationCode}."
        );
    }
}
