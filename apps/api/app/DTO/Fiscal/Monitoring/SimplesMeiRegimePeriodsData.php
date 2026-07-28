<?php

namespace App\DTO\Fiscal\Monitoring;

use Illuminate\Support\Collection;

final readonly class SimplesMeiRegimePeriodsData
{
    /** @param Collection<int, object> $periods */
    public function __construct(
        public Collection $periods,
        public mixed $currentTaxRegime,
    ) {}
}
