<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalYearFilters
{
    public function __construct(
        public ?int $year,
    ) {}
}
