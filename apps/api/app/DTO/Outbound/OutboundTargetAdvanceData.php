<?php

namespace App\DTO\Outbound;

use App\Domain\Outbound\Competence;
use Carbon\CarbonImmutable;

final readonly class OutboundTargetAdvanceData
{
    public function __construct(
        public Competence $competence,
        public CarbonImmutable $targetAt,
    ) {}
}
