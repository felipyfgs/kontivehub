<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SicalcRevenueSupportFilters
{
    public function __construct(
        public ?string $revenueCode,
    ) {}
}
