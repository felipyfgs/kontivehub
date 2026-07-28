<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxObligationProjection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxObligationProjection */
class TaxObligationProjectionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxObligationProjection $projection */
        $projection = $this->resource;
        $module = $projection->relationLoaded('obligation')
            ? $projection->obligation?->module_key
            : null;

        return [
            'id' => $projection->id,
            'tenant_id' => $projection->tenant_id,
            'client_id' => $projection->client_id,
            'obligation_definition_id' => $projection
                ->obligation_definition_id,
            'obligation_code' => $projection->relationLoaded('obligation')
                ? $projection->obligation?->code
                : null,
            'obligation_name' => $projection->relationLoaded('obligation')
                ? $projection->obligation?->name
                : null,
            'module_key' => $module,
            'system_code' => $projection->relationLoaded('obligation')
                ? $projection->obligation?->system_code
                : null,
            'service_code' => $projection->relationLoaded('obligation')
                ? $projection->obligation?->service_code
                : null,
            'obligation_version_id' => $projection->obligation_version_id,
            'calendar_version_id' => $projection->calendar_version_id,
            'competence_id' => $projection->competence_id,
            'period_key' => $projection->period_key,
            'period_year' => $projection->period_year,
            'period_month' => $projection->period_month,
            'applicability' => $projection->applicability?->value,
            'situation' => $projection->situation?->value,
            'delivery_status' => $projection->delivery_status?->value,
            'due_at' => $projection->due_at?->toIso8601String(),
            'applicability_basis' => $projection->applicability_basis,
            'is_open' => $projection->is_open,
            'closed_at' => $projection->closed_at?->toIso8601String(),
            'conclusive_evidence_id' => $projection
                ->conclusive_evidence_id,
            'evidence_artifact_id' => $projection->evidence_artifact_id,
            'last_valid_query_at' => $projection->last_valid_query_at
                ?->toIso8601String(),
            'obligation_version' => $projection
                ->relationLoaded('obligationVersion')
                ? $projection->obligationVersion?->toPublicArray()
                : null,
            'calendar_version' => $projection
                ->relationLoaded('calendarVersion')
                && $projection->calendarVersion !== null
                ? (new TaxDeadlineCalendarVersionResource(
                    $projection->calendarVersion,
                ))->resolve($request)
                : null,
            'deep_links' => [
                'self' => '/api/v1/fiscal/declarations/'.$projection->id,
                'module' => $module !== null
                    ? '/api/v1/fiscal/declarations?module_key='
                        .urlencode($module)
                    : null,
                'evidence' => $projection->evidence_artifact_id !== null
                    ? '/api/v1/fiscal/evidence/'
                        .$projection->evidence_artifact_id.'/download'
                    : null,
                'conclusive_evidence' => $projection
                    ->conclusive_evidence_id !== null
                    ? '/api/v1/fiscal/declarations/'.$projection->id
                        .'/evidences/'.$projection->conclusive_evidence_id
                    : null,
                'client' => '/api/v1/clients/'.$projection->client_id,
                'competence' => $projection->competence_id !== null
                    ? '/api/v1/fiscal/declarations?competence_id='
                        .$projection->competence_id
                    : null,
            ],
        ];
    }
}
