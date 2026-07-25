<?php

namespace App\Domain\Outbound;

use App\Enums\OutboundDeadlineSource;
use App\Enums\OutboundUrgencyBand;
use Carbon\CarbonImmutable;

final readonly class DeadlinePlan
{
    public function __construct(
        public Competence $competence,
        public CarbonImmutable $dueAt,
        public CarbonImmutable $targetAt,
        public OutboundDeadlineSource $source,
        public OutboundUrgencyBand $band,
        public bool $provisional,
    ) {}
}
