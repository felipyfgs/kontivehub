<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalSnapshotFilters
{
    public function __construct(
        public int $perPage,
        public ?int $clientId,
        public bool $currentOnly,
    ) {}
}
