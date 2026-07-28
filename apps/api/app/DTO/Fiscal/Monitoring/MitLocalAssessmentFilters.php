<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class MitLocalAssessmentFilters
{
    public function __construct(
        public int $clientId,
        public ?int $year,
    ) {}
}
