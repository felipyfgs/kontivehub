<?php

namespace App\DTO\Fiscal\Mutations;

use App\Models\TaxGuide;
use App\Models\TaxGuidePaymentConfirmation;

final readonly class TaxGuidePaymentResultData
{
    public function __construct(
        public TaxGuide $guide,
        public ?TaxGuidePaymentConfirmation $confirmation,
        public string $lookupStatus,
    ) {}
}
