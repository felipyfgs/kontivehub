<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalMonitoringRunFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
        public ?string $status,
    ) {}
}
