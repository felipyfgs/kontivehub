<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalPageFilters
{
    public function __construct(
        public int $page,
        public int $perPage,
    ) {}
}
