<?php

namespace App\Contracts;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;

/**
 * Contrato para módulos filhos (SN/MEI, DCTFWeb, SITFIS, …).
 * O núcleo NÃO chama SERPRO real — adapters implementam a fonte.
 */
interface FiscalSourceAdapter
{
    public function systemCode(): string;

    public function serviceCode(): string;

    public function operationCode(): string;

    public function mutability(): FiscalMutability;

    public function coverage(): FiscalCoverage;

    /** FeatureFlags module key (simples_mei, sitfis, …) ou null. */
    public function moduleKey(): ?string;

    public function supports(FiscalAdapterRequest $request): bool;

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult;
}
