<?php

namespace App\Http\Resources\Work;

use App\Models\WorkProcessGenerationBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ProcessGenerationBatchSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessGenerationBatch $batch */
        $batch = $this->resource;

        return [
            'id' => $batch->id,
            'work_process_template_id' => $batch->work_process_template_id,
            'competence' => $batch->competence,
            'reference_period_type' => $batch->reference_period_type,
            'status' => $batch->status->value,
            'idempotency_key' => $batch->idempotency_key,
            'preview_summary' => $batch->preview_summary,
            'queued_at' => $batch->queued_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'created_at' => $batch->created_at?->toIso8601String(),
        ];
    }
}
