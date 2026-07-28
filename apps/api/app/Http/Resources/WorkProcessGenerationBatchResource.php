<?php

namespace App\Http\Resources;

use App\Domain\Work\ReferencePeriod;
use App\Models\WorkProcessGenerationBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

final class WorkProcessGenerationBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var WorkProcessGenerationBatch $batch */
        $batch = $this->resource;

        return [
            'id' => $batch->id,
            'work_process_template_id' => $batch->work_process_template_id,
            'template_lock_version' => $batch->template_lock_version,
            'competence' => $batch->competence,
            'reference_period' => $this->referencePeriod($batch),
            'status' => $batch->status->value,
            'payload_hash' => $batch->payload_hash,
            'idempotency_key' => $batch->idempotency_key,
            'preview_summary' => $batch->preview_summary,
            'expires_at' => $batch->expires_at?->toIso8601String(),
            'queued_at' => $batch->queued_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'items' => $batch->relationLoaded('items')
                ? WorkProcessGenerationItemResource::collection($batch->items)
                : [],
        ];
    }

    /**
     * @return array{type: string, key: string, start: string, end: string}|null
     */
    private function referencePeriod(WorkProcessGenerationBatch $batch): ?array
    {
        if ($batch->reference_period_type
            && $batch->reference_period_start
            && $batch->reference_period_end) {
            return [
                'type' => (string) $batch->reference_period_type,
                'key' => (string) $batch->competence,
                'start' => $batch->reference_period_start->format('Y-m-d'),
                'end' => $batch->reference_period_end->format('Y-m-d'),
            ];
        }

        try {
            return ReferencePeriod::fromString((string) $batch->competence)->toArray();
        } catch (InvalidArgumentException) {
            return null;
        }
    }
}
