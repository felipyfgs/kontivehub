<?php

namespace App\DTO\Fiscal\SimplesMei;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\TaxRegimeCode;

/**
 * Definição versionada de operação no catálogo Simples/MEI.
 */
final readonly class SimplesMeiOperationDef
{
    /**
     * @param  list<string>  $requiredPowers  códigos de poder (OR — qualquer um serve)
     */
    public function __construct(
        public string $systemCode,
        public string $serviceCode,
        public string $operationCode,
        public string $dtoVersion,
        public FiscalMutability $mutability,
        public FiscalCoverage $coverage,
        public TaxRegimeCode $regimeFamily,
        public array $requiredPowers = [],
        public string $billableClass = 'CONSULTA',
        public bool $isMonitor = false,
        public string $label = '',
    ) {}

    public function moduleKey(): string
    {
        return 'simples_mei';
    }

    public function catalogKey(): string
    {
        return strtoupper("{$this->systemCode}/{$this->serviceCode}/{$this->operationCode}");
    }
}
