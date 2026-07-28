<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class TaxGuideFilters
{
    public function __construct(
        public int $page,
        public int $perPage,
        public ?int $clientId,
        public ?string $paymentStatus,
        public string $sort,
        public string $sortDirection,
    ) {}
}
