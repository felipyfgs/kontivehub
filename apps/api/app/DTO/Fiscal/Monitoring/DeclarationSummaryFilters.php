<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class DeclarationSummaryFilters
{
    public function __construct(
        public ?int $clientId,
        public ?string $periodKey,
    ) {}
}
