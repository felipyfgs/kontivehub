<?php

namespace App\Http\Resources\Fiscal;

use App\Models\FiscalMonitoringRun;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FiscalMonitoringRun */
final class FiscalMonitoringRunResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FiscalMonitoringRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'client_id' => $run->client_id,
            'fiscal_category_id' => $run->fiscal_category_id,
            'competence_id' => $run->competence_id,
            'schedule_id' => $run->schedule_id,
            'system_code' => $run->system_code,
            'service_code' => $run->service_code,
            'operation_code' => $run->operation_code,
            'operation_key' => $run->operation_key,
            'source_provenance' => $run->source_provenance instanceof BackedEnum
                ? $run->source_provenance->value
                : $run->source_provenance,
            'verification_state' => $run->verification_state instanceof BackedEnum
                ? $run->verification_state->value
                : $run->verification_state,
            'trigger' => $run->trigger?->value,
            'idempotency_key' => $run->idempotency_key,
            'status' => $run->status?->value,
            'result' => $run->result?->value,
            'situation' => $run->situation?->value,
            'coverage' => $run->coverage?->value,
            'mutability' => $run->mutability?->value,
            'attempt' => $run->attempt,
            'parent_run_id' => $run->parent_run_id,
            'correlation_id' => $run->correlation_id,
            'progress_cursor' => $run->progress_cursor,
            'items_processed' => $run->items_processed,
            'pages_processed' => $run->pages_processed,
            'skip_reason' => $run->skip_reason,
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'requeued_at' => $run->requeued_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
