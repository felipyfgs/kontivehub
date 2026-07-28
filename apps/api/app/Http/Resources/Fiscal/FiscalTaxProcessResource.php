<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalTaxProcess;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalTaxProcess */
final class FiscalTaxProcessResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalTaxProcess $process */
        $process = $this->resource;

        return [
            'id' => $process->id,
            'client_id' => $process->client_id,
            'contributor_ref' => substr(
                hash('sha256', (string) $process->contributor_cnpj),
                0,
                12,
            ),
            'process_number' => $process->process_number,
            'status' => $process->status,
            'evidence_version' => $process->evidence_version,
            'operation_key' => $process->operation_key,
            'source_provenance' => $process->source_provenance,
            'is_simulated' => $process->is_simulated,
            'summary' => $process->summary_sanitized,
            'observed_at' => $process->observed_at?->toIso8601String(),
            'refreshed_at' => $process->refreshed_at?->toIso8601String(),
        ];
    }
}
