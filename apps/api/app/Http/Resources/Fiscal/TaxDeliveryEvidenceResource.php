<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxDeliveryEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxDeliveryEvidence */
final class TaxDeliveryEvidenceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxDeliveryEvidence $evidence */
        $evidence = $this->resource;

        return [
            'id' => $evidence->id,
            'tenant_id' => $evidence->tenant_id,
            'projection_id' => $evidence->projection_id,
            'kind' => $evidence->kind?->value,
            'protocol_number' => $evidence->protocol_number,
            'receipt_number' => $evidence->receipt_number,
            'is_conclusive' => $evidence->is_conclusive,
            'source' => $evidence->source,
            'source_version' => $evidence->source_version,
            'observed_at' => $evidence->observed_at?->toIso8601String(),
            'evidence_artifact_id' => $evidence->evidence_artifact_id,
            'run_id' => $evidence->run_id,
            'payload_digest' => $evidence->payload_digest,
            'deep_links' => [
                'projection' => '/api/v1/fiscal/declarations/'
                    .$evidence->projection_id,
                'evidence_download' => $evidence
                    ->evidence_artifact_id !== null
                    ? '/api/v1/fiscal/evidence/'
                        .$evidence->evidence_artifact_id.'/download'
                    : null,
                'run' => $evidence->run_id !== null
                    ? '/api/v1/fiscal/runs/'.$evidence->run_id
                    : null,
            ],
        ];
    }
}
