<?php

namespace App\Http\Resources\FgtsDigital;

use App\Models\FgtsDigitalRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsDigitalRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'client_id' => $run->client_id,
            'operation' => $run->operation->value,
            'guide_type' => $run->guide_type?->value,
            'status' => $run->status->value,
            'code' => $run->code,
            'confirmation_phrase' => $run->confirmation_phrase,
            'preview_expires_at' => $run->preview_expires_at?->toIso8601String(),
            'request' => $run->request_sanitized,
            'result' => $run->result_sanitized,
            'tax_guide_id' => $run->tax_guide_id,
            'tax_guide_version_id' => $run->tax_guide_version_id,
            'fiscal_mutation_operation_id' => $run->fiscal_mutation_operation_id,
            'correlation_id' => $run->correlation_id,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
