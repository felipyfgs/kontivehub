<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Domain\Work\ReferencePeriod;
use App\Http\Controllers\Controller;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessGenerationItem;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessGenerationService;
use App\Support\CurrentTenant;
use App\Support\Work\RejectClientTenantId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class WorkProcessGenerationController extends Controller
{
    public function preview(Request $request, WorkProcessTemplate $template, WorkProcessGenerationService $service): JsonResponse
    {
        $this->authorize('generate', $template);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'competence' => ['required', 'string', 'max:16'],
            'selection' => ['present', 'array'],
            'selection.rules' => ['sometimes', 'array'],
            'selection.rules.tax_regimes' => ['sometimes', 'array', 'max:6'],
            'selection.rules.tax_regimes.*' => ['string', 'max:40'],
            'selection.rules.category_ids' => ['sometimes', 'array', 'max:100'],
            'selection.rules.category_ids.*' => ['integer', 'min:1'],
            'selection.rules.category_match' => ['sometimes', 'string', 'in:ANY,ALL'],
            'selection.rules.excluded_category_ids' => ['sometimes', 'array', 'max:100'],
            'selection.rules.excluded_category_ids.*' => ['integer', 'min:1'],
            'selection.include_client_ids' => ['sometimes', 'array', 'max:1000'],
            'selection.include_client_ids.*' => ['integer', 'min:1'],
            'selection.exclude_client_ids' => ['sometimes', 'array', 'max:1000'],
            'selection.exclude_client_ids.*' => ['integer', 'min:1'],
            'overrides' => ['sometimes', 'array'],
            'overrides.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'overrides.target_due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'overrides.subject_to_fine' => ['sometimes', 'boolean'],
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $batch = $service->preview(
            $template,
            $data['competence'],
            $data['selection'] ?? [],
            $data['overrides'] ?? [],
            $data['idempotency_key'] ?? null,
        );

        return response()->json(['data' => $this->publicBatch($batch)], 201);
    }

    public function confirm(Request $request, WorkProcessGenerationBatch $batch, WorkProcessGenerationService $service): JsonResponse
    {
        $this->authorize('viewAny', WorkProcessTemplate::class);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'idempotency_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        // Autorização via template do batch
        $template = WorkProcessTemplate::query()->findOrFail($batch->work_process_template_id);
        $this->authorize('generate', $template);

        $batch = $service->confirm($batch, $data['idempotency_key'] ?? null);

        return response()->json(['data' => $this->publicBatch($batch)]);
    }

    public function retry(Request $request, WorkProcessGenerationBatch $batch, WorkProcessGenerationService $service): JsonResponse
    {
        RejectClientTenantId::strip($request);

        if ((int) $batch->tenant_id !== (int) app(CurrentTenant::class)->id()) {
            abort(404);
        }

        $template = WorkProcessTemplate::query()->findOrFail($batch->work_process_template_id);
        $this->authorize('retryGeneration', $template);

        $batch = $service->retryFailedItems($batch);

        return response()->json(['data' => $this->publicBatch($batch)]);
    }

    public function show(WorkProcessGenerationBatch $batch): JsonResponse
    {
        $this->authorize('viewAny', WorkProcessTemplate::class);
        if ((int) $batch->tenant_id !== (int) app(CurrentTenant::class)->id()) {
            abort(404);
        }

        $batch->load('items');

        return response()->json(['data' => $this->publicBatch($batch)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function publicBatch(WorkProcessGenerationBatch $batch): array
    {
        return [
            'id' => $batch->id,
            'work_process_template_id' => $batch->work_process_template_id,
            'template_lock_version' => $batch->template_lock_version,
            'competence' => $batch->competence,
            'reference_period' => $this->batchReferencePeriod($batch),
            'status' => $batch->status->value,
            'payload_hash' => $batch->payload_hash,
            'idempotency_key' => $batch->idempotency_key,
            'preview_summary' => $batch->preview_summary,
            'expires_at' => $batch->expires_at?->toIso8601String(),
            'queued_at' => $batch->queued_at?->toIso8601String(),
            'completed_at' => $batch->completed_at?->toIso8601String(),
            'items' => $batch->relationLoaded('items')
                ? $batch->items->map(fn (WorkProcessGenerationItem $i) => [
                    'id' => $i->id,
                    'client_id' => $i->client_id,
                    'status' => $i->status->value,
                    'is_blocked' => $i->is_blocked,
                    'preview_payload' => $i->preview_payload,
                    'alerts' => $i->alerts,
                    'conflicts' => $i->conflicts,
                    'created_process_id' => $i->created_process_id,
                    'error_message' => $i->error_message,
                ])->values()
                : [],
        ];
    }

    /**
     * @return array{type: string, key: string, start: string, end: string}|null
     */
    private function batchReferencePeriod(WorkProcessGenerationBatch $batch): ?array
    {
        if ($batch->reference_period_type && $batch->reference_period_start && $batch->reference_period_end) {
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
