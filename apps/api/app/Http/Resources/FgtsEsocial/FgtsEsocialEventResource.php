<?php

namespace App\Http\Resources\FgtsEsocial;

use App\Models\EsocialEventEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialEventResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EsocialEventEvidence $evidence */
        $evidence = $this->resource;

        return [
            'id' => $evidence->id,
            'tenant_id' => $evidence->tenant_id,
            'client_id' => $evidence->client_id,
            'establishment_id' => $evidence->establishment_id,
            'run_id' => $evidence->run_id,
            'competence_period_key' => $evidence->competence_period_key,
            'event_code' => $evidence->event_code?->value,
            'event_label' => $evidence->event_code?->label(),
            'event_version' => $evidence->event_version,
            'receipt_number' => $evidence->receipt_number,
            'establishment_cnpj' => $evidence->establishment_cnpj,
            'content_sha256' => $evidence->content_sha256,
            'byte_size' => $evidence->byte_size,
            'source' => $evidence->source,
            'source_version' => $evidence->source_version,
            'occurred_at' => $evidence->occurred_at?->toIso8601String(),
            'observed_at' => $evidence->observed_at?->toIso8601String(),
            'is_totalizer' => $evidence->event_code?->isTotalizer() ?? false,
            'is_closure' => $evidence->event_code?->isClosure() ?? false,
            'is_quarantined' => $evidence->is_quarantined,
            'quarantine_reason' => $evidence->quarantine_reason,
        ];
    }
}
