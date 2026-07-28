<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalPaginatedClientFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
    ) {}
}
