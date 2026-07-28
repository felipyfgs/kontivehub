<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class FiscalCategoryLinkFilters
{
    public function __construct(
        public ?int $clientId,
        public ?string $status,
    ) {}
}
