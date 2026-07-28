<?php

namespace App\DTO\Outbound;

use App\Models\OutboundMonthlyReadiness;

final readonly class OutboundCompetenceSummaryData
{
    /**
     * @param  array<string, mixed>  $stats
     * @param  array<string, int>  $byCaptureSource
     */
    public function __construct(
        public string $competence,
        public array $stats,
        public array $byCaptureSource,
        public OutboundMonthlyReadiness $readiness,
    ) {}
}
