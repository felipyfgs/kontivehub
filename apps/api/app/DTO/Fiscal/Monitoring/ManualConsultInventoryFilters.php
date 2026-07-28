<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class ManualConsultInventoryFilters
{
    public function __construct(
        public ?int $clientId,
        public ?string $surfaceKey,
        public ?string $moduleKey,
    ) {}
}
