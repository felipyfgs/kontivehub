<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundMonthlyReadiness;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundMonthlyReadinessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundMonthlyReadiness $readiness */
        $readiness = $this->resource;

        return [
            'competence' => $readiness->competence,
            'status' => $readiness->status->value,
            'status_label' => $readiness->status->label(),
            'known_total' => $readiness->known_total,
            'captured_total' => $readiness->captured_total,
            'pending_total' => $readiness->pending_total,
            'export_id' => $readiness->export_id,
            'confirmed_at' => $readiness->confirmed_at?->toIso8601String(),
            'summary' => $readiness->summary,
            'completeness_scope' => 'known_documents_only',
        ];
    }
}
