<?php

namespace App\DTO\Outbound;

use Carbon\CarbonImmutable;

final readonly class OutboundTargetAdvanceResult
{
    public function __construct(
        public string $competence,
        public CarbonImmutable $targetAt,
        public CarbonImmutable $dueAt,
        public int $updatedRows,
    ) {}
}
