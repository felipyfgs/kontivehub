<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class DefisSpecificDeclarationFilters
{
    public function __construct(
        public ?int $referenceId,
    ) {}
}
