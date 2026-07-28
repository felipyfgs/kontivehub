<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundCompetenceSummaryData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCompetenceSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCompetenceSummaryData $result */
        $result = $this->resource;

        return [
            'competence' => $result->competence,
            'known_total' => $result->stats['known_total'],
            'captured_total' => $result->stats['captured_total'],
            'pending_total' => $result->stats['pending_total'],
            'by_band' => $result->stats['by_band'],
            'by_capture_source' => $result->byCaptureSource,
            'readiness' => (new OutboundMonthlyReadinessResource(
                $result->readiness,
            ))->resolve($request),
            'completeness_scope' => 'known_documents_only',
            'sla_note' => 'SLA operacional interno (dia 1) — não é prazo legal.',
        ];
    }
}
