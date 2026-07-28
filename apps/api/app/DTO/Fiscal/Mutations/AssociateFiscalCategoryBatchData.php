<?php

namespace App\DTO\Fiscal\Mutations;

use App\Enums\FiscalCoverage;

final readonly class AssociateFiscalCategoryBatchData
{
    /**
     * @param  list<int>  $clientIds
     */
    public function __construct(
        public int $fiscalCategoryId,
        public array $clientIds,
        public ?FiscalCoverage $coverage,
    ) {}
}
