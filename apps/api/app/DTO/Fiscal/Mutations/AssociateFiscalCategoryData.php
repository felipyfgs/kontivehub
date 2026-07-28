<?php

namespace App\DTO\Fiscal\Mutations;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;

final readonly class AssociateFiscalCategoryData
{
    public function __construct(
        public int $clientId,
        public int $fiscalCategoryId,
        public ?FiscalCoverage $coverage,
        public FiscalLinkStatus $status,
        public ?string $notes,
    ) {}
}
