<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SimplesMeiHistoryFilters
{
    public function __construct(
        public ?int $year,
    ) {}
}
