<?php

namespace App\Http\Resources\FgtsEsocial;

use App\Models\FgtsCompetenceStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialCompetenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsCompetenceStatus $status */
        $status = $this->resource;

        return [
            'id' => $status->id,
            'tenant_id' => $status->tenant_id,
            'client_id' => $status->client_id,
            'establishment_id' => $status->establishment_id,
            'competence_period_key' => $status->competence_period_key,
            'closure_status' => $status->closure_status?->value,
            'closure_status_label' => $status->closure_status?->label(),
            'totalization_status' => $status->totalization_status?->value,
            'totalization_status_label' => $status->totalization_status?->label(),
            'guide_status' => $status->guide_status?->value,
            'guide_status_label' => $status->guide_status?->label(),
            'payment_status' => $status->payment_status?->value,
            'payment_status_label' => $status->payment_status?->label(),
            'coverage' => $status->coverage?->value,
            'situation' => $status->situation?->value,
            'closure_observed_at' => $status->closure_observed_at?->toIso8601String(),
            'totalizer_observed_at' => $status->totalizer_observed_at?->toIso8601String(),
            'totalizer_due_by' => $status->totalizer_due_by?->toIso8601String(),
            'last_synced_at' => $status->last_synced_at?->toIso8601String(),
            'limitations' => $status->limitations ?? [],
            'partial_coverage' => true,
            'declares_fgts_digital_debt' => false,
            'run_id' => $status->run_id,
            'snapshot_id' => $status->snapshot_id,
            'is_quarantined' => $status->is_quarantined,
            'quarantine_reason' => $status->quarantine_reason,
        ];
    }
}
