<?php

namespace App\DTO\Fiscal\Mutations;

use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;

final readonly class TaxGuideReconcileResultData
{
    public function __construct(
        public TaxGuide $guide,
        public TaxGuideVersion $version,
        public string $outcome,
    ) {}
}
