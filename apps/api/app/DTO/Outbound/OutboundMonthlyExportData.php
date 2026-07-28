<?php

namespace App\DTO\Outbound;

use App\Domain\Outbound\Competence;

final readonly class OutboundMonthlyExportData
{
    public function __construct(
        public Competence $competence,
        public bool $includeEvents,
        public ?string $notes,
    ) {}
}
