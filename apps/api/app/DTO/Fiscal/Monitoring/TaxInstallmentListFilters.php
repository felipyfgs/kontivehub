<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class TaxInstallmentListFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId = null,
        public ?int $orderId = null,
        public ?string $modality = null,
    ) {}
}
