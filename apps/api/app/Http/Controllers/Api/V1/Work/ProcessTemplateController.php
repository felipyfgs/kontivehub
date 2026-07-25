<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Http\Controllers\Controller;
use App\Models\ProcessGenerationBatch;
use App\Models\ProcessTemplate;
use App\Services\Work\ProcessTemplateRecurrenceService;
use App\Services\Work\ProcessTemplateWriter;
use App\Support\CurrentOffice;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProcessTemplateController extends Controller
{
    public function __construct(
        private readonly ProcessTemplateWriter $writer,
        private readonly ProcessTemplateRecurrenceService $recurrence,
    ) {}

    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('viewAny', ProcessTemplate::class);
        RejectClientOfficeId::strip($request);

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $q = ProcessTemplate::query()
            ->with('tasks')
            ->where('office_id', $currentOffice->id());

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
            'data' => collect($paginator->items())->map(fn (ProcessTemplate $t) => $this->writer->toPublic($t)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(ProcessTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);
        $template->load('tasks');

        return response()->json(['data' => $this->writer->toPublic($template)]);
    }

    public function store(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('create', ProcessTemplate::class);
        RejectClientOfficeId::strip($request);

        $template = $this->writer->create($request->all());

        return response()->json(['data' => $this->writer->toPublic($template)], 201);
    }

    public function update(Request $request, ProcessTemplate $template): JsonResponse
    {
        $this->authorize('update', $template);
        RejectClientOfficeId::strip($request);

        $template = $this->writer->update($template, $request->all());

        return response()->json(['data' => $this->writer->toPublic($template)]);
    }

    public function showRecurrence(Request $request, ProcessTemplate $template): JsonResponse
    {
        $this->authorize('view', $template);
        RejectClientOfficeId::strip($request);

        return response()->json([
            'data' => $this->recurrence->toPublic($template) + [
                'lock_version' => $template->lock_version,
            ],
        ]);
    }

    public function updateRecurrence(Request $request, ProcessTemplate $template): JsonResponse
    {
        $this->authorize('manageRecurrence', $template);
        RejectClientOfficeId::strip($request);

        $template = $this->recurrence->update($template, $request->all());

        return response()->json([
            'data' => $this->recurrence->toPublic($template) + [
                'lock_version' => $template->lock_version,
            ],
        ]);
    }

    public function generationBatches(Request $request, ProcessTemplate $template, CurrentOffice $currentOffice): JsonResponse
    {
        $this->authorize('viewGenerations', $template);
        RejectClientOfficeId::strip($request);

        if ((int) $template->office_id !== (int) $currentOffice->id()) {
            abort(404);
        }

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $q = ProcessGenerationBatch::query()
            ->where('office_id', $currentOffice->id())
            ->where('process_template_id', $template->id)
            ->orderByDesc('id');

        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->filled('competence')) {
            $q->where('competence', $request->string('competence')->toString());
        }

        $paginator = $q->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (ProcessGenerationBatch $batch) => [
                'id' => $batch->id,
                'process_template_id' => $batch->process_template_id,
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
