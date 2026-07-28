<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalSnapshot;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalSnapshot */
final class FiscalSnapshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalSnapshot $snapshot */
        $snapshot = $this->resource;

        return [
            'id' => $snapshot->id,
            'tenant_id' => $snapshot->tenant_id,
            'run_id' => $snapshot->run_id,
            'client_id' => $snapshot->client_id,
            'competence_id' => $snapshot->competence_id,
            'evidence_artifact_id' => $snapshot->evidence_artifact_id,
            'system_code' => $snapshot->system_code,
            'service_code' => $snapshot->service_code,
            'operation_code' => $snapshot->operation_code,
            'operation_key' => $snapshot->operation_key,
            'source_provenance' => $snapshot->source_provenance
                instanceof BackedEnum
                ? $snapshot->source_provenance->value
                : $snapshot->source_provenance,
            'verification_state' => $snapshot->verification_state
                instanceof BackedEnum
                ? $snapshot->verification_state->value
                : $snapshot->verification_state,
            'situation' => $snapshot->situation?->value,
            'coverage' => $snapshot->coverage?->value,
            'version' => $snapshot->version,
            'is_current' => $snapshot->is_current,
            'normalized' => $snapshot->normalized,
            'observed_at' => $snapshot->observed_at?->toIso8601String(),
            'created_at' => $snapshot->created_at?->toIso8601String(),
        ];
    }
}
