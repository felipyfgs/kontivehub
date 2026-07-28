<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SimplesMeiCompetenceFilters
{
    public function __construct(
        public ?string $regimeFamily,
    ) {}
}
