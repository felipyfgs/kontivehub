<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Models\WorkProcessGenerationBatch;
use App\Models\WorkProcessTemplate;
use App\Services\Work\WorkProcessTemplateRecurrenceService;
use App\Services\Work\WorkProcessTemplateWriter;
use App\Support\CurrentTenant;
use App\Support\Work\RejectClientTenantId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkProcessTemplateController extends Controller
{
    public function __construct(
        private readonly WorkProcessTemplateWriter $writer,
        private readonly WorkProcessTemplateRecurrenceService $recurrence,
    ) {}

    public function index(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('viewAny', WorkProcessTemplate::class);
        RejectClientTenantId::strip($request);

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $q = WorkProcessTemplate::query()
            ->with('tasks')
            ->where('tenant_id', $currentTenant->id());

        if ($request->filled('is_active')) {
            $q->where('is_active', $request->boolean('is_active'));
        }
        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower($request->string('q')->toString()).'%';
            $q->where(function ($search) use ($needle): void {
                $search->whereRaw('LOWER(name) LIKE ?', [$needle])
                    ->orWhereRaw('LOWER(COALESCE(description, \'\')) LIKE ?', [$needle]);
            });
        }

        $sort = match ($request->string('sort')->toString()) {
            'is_active' => 'is_active',
            'id' => 'id',
            default => 'name',
        };
        $defaultDirection = $sort === 'name' ? 'asc' : 'desc';
        $requestedDirection = $request->string('direction')->lower()->toString();
        $direction = in_array($requestedDirection, ['asc', 'desc'], true)
            ? $requestedDirection
            : $defaultDirection;
        $q->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $q->orderBy('id', $direction);
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (WorkProcessTemplate $t) => $this->writer->toPublic($t)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(WorkProcessTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);
        $template->load('tasks');

        return response()->json(['data' => $this->writer->toPublic($template)]);
    }

    public function store(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('create', WorkProcessTemplate::class);
        RejectClientTenantId::strip($request);

        $template = $this->writer->create($request->all());

        return response()->json(['data' => $this->writer->toPublic($template)], 201);
    }

    public function update(Request $request, WorkProcessTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);
        RejectClientTenantId::strip($request);

        $template = $this->writer->update($template, $request->all());

        return response()->json(['data' => $this->writer->toPublic($template)]);
    }

    public function showRecurrence(Request $request, WorkProcessTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);
        RejectClientTenantId::strip($request);

        return response()->json([
            'data' => $this->recurrence->toPublic($template) + [
                'lock_version' => $template->lock_version,
            ],
        ]);
    }

    public function updateRecurrence(Request $request, WorkProcessTemplate $template): JsonResponse
    {
        $this->authorize('manageRecurrence', $template);
        RejectClientTenantId::strip($request);

        $template = $this->recurrence->update($template, $request->all());

        return response()->json([
            'data' => $this->recurrence->toPublic($template) + [
                'lock_version' => $template->lock_version,
            ],
        ]);
    }

    public function generationBatches(Request $request, WorkProcessTemplate $template, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('viewGenerations', $template);
        RejectClientTenantId::strip($request);

        if ((int) $template->tenant_id !== (int) $currentTenant->id()) {
            abort(404);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $q = WorkProcessGenerationBatch::query()
            ->where('tenant_id', $currentTenant->id())
            ->where('work_process_template_id', $template->id)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('competence')) {
            $q->where('competence', $request->string('competence')->toString());
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (WorkProcessGenerationBatch $batch) => [
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
            ]),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }
}
