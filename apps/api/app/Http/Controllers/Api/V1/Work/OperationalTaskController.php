<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\QueueBucketResolver;
use App\Domain\Work\WorkRiskCalculator;
use App\Http\Controllers\Controller;
use App\Models\Office;
use App\Models\OperationalComment;
use App\Models\OperationalProcess;
use App\Models\OperationalTask;
use App\Models\OperationalTaskEvidence;
use App\Services\Audit\AuditLogger;
use App\Services\Work\MembershipResolver;
use App\Services\Work\OperationalEvidenceService;
use App\Services\Work\OperationalProcessService;
use App\Services\Work\OperationalQueueQuery;
use App\Services\Work\OperationalTaskStructureService;
use App\Services\Work\OperationalTaskTransitionService;
use App\Services\Work\OperationalWorkBulkService;
use App\Support\CurrentOffice;
use App\Support\Work\OfficeTimezone;
use App\Support\Work\OptimisticLock;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class OperationalTaskController extends Controller
{
    public function queue(Request $request, OperationalQueueQuery $query): JsonResponse
    {
        $this->authorize('viewAny', OperationalTask::class);
        RejectClientOfficeId::strip($request);

        $paginator = $query->paginate([
            'tab' => $request->string('tab')->toString() ?: 'open',
            'department_id' => $request->input('department_id'),
            'assignee_membership_id' => $request->input('assignee_membership_id'),
            'client_id' => $request->input('client_id'),
            'q' => $request->input('q'),
            'per_page' => $request->input('per_page', 25),
            'page' => $request->input('page', 1),
            'scope' => $request->input('scope', 'default'),
            'sort' => $request->input('sort'),
            'direction' => $request->input('direction'),
        ]);

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(OperationalTask $task): JsonResponse
    {
        $this->authorize('view', $task);
        $task->load([
            'process.client',
            'department',
            'assigneeMembership.user',
            'evidences',
            'comments',
        ]);

        return response()->json(['data' => $this->public($task, detailed: true)]);
    }

    public function start(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('transition', $task);
        $lock = (int) $request->input('lock_version');
        $task = $service->start($task, $lock);

        return response()->json(['data' => $this->public($task)]);
    }

    public function block(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('transition', $task);
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'reason' => ['required', 'string', 'max:2000'],
        ]);
        $task = $service->block($task, (int) $data['lock_version'], $data['reason']);

        return response()->json(['data' => $this->public($task)]);
    }

    public function resume(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('transition', $task);
        $task = $service->resume($task, (int) $request->input('lock_version'));

        return response()->json(['data' => $this->public($task)]);
    }

    public function complete(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('transition', $task);
        $task = $service->complete($task, (int) $request->input('lock_version'));

        return response()->json(['data' => $this->public($task)]);
    }

    public function dispense(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('dispense', $task);
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'justification' => ['required', 'string', 'max:2000'],
        ]);
        $task = $service->dispense($task, (int) $data['lock_version'], $data['justification']);

        return response()->json(['data' => $this->public($task)]);
    }

    public function reopen(Request $request, OperationalTask $task, OperationalTaskTransitionService $service): JsonResponse
    {
        $this->authorize('reopen', $task);
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'justification' => ['required', 'string', 'max:2000'],
        ]);
        $task = $service->reopen($task, (int) $data['lock_version'], $data['justification']);

        return response()->json(['data' => $this->public($task)]);
    }

    public function claim(Request $request, OperationalTask $task, OperationalProcessService $service): JsonResponse
    {
        $this->authorize('claim', $task);
        $task = $service->claimTask($task, (int) $request->input('lock_version'));

        return response()->json(['data' => $this->public($task)]);
    }

    public function assign(
        Request $request,
        OperationalTask $task,
        MembershipResolver $memberships,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('assign', $task);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'work_department_id' => ['nullable', 'integer'],
        ]);

        // Null limpa assignee/dept; IDs devem pertencer ao escritório da sessão.
        if (array_key_exists('assignee_membership_id', $data) && $data['assignee_membership_id'] !== null) {
            $memberships->requireActiveMembership((int) $data['assignee_membership_id']);
        }
        if (array_key_exists('work_department_id', $data) && $data['work_department_id'] !== null) {
            $memberships->requireActiveDepartment((int) $data['work_department_id']);
        }

        OptimisticLock::assert($task, (int) $data['lock_version'], 'operational_task');
        $attrs = [];
        if (array_key_exists('assignee_membership_id', $data)) {
            $attrs['assignee_membership_id'] = $data['assignee_membership_id'];
        }
        if (array_key_exists('work_department_id', $data)) {
            $attrs['work_department_id'] = $data['work_department_id'];
        }
        OptimisticLock::updateOrConflict($task, (int) $data['lock_version'], $attrs, 'operational_task');
        $audit->record('work.task.assign', 'SUCCESS', $task, $attrs);

        return response()->json(['data' => $this->public($task->fresh())]);
    }

    public function storeOnProcess(
        Request $request,
        OperationalProcess $process,
        OperationalTaskStructureService $structure,
    ): JsonResponse {
        $this->authorize('update', $process);
        RejectClientOfficeId::strip($request);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['sometimes', 'integer', 'min:1'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'is_required' => ['sometimes', 'boolean'],
            'is_critical' => ['sometimes', 'boolean'],
            'requires_evidence' => ['sometimes', 'boolean'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);
        $task = $structure->addTask($process, $data);

        return response()->json(['data' => $this->public($task)], 201);
    }

    public function updateStructure(
        Request $request,
        OperationalTask $task,
        OperationalTaskStructureService $structure,
    ): JsonResponse {
        // Estrutura: capability do processo (não a policy de executor da tarefa).
        $process = $task->process()->firstOrFail();
        $this->authorize('update', $process);
        RejectClientOfficeId::strip($request);
        $data = $request->validate([
            'lock_version' => ['required', 'integer'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'is_required' => ['sometimes', 'boolean'],
            'is_critical' => ['sometimes', 'boolean'],
            'requires_evidence' => ['sometimes', 'boolean'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);
        $task = $structure->updateTask($task, (int) $data['lock_version'], $data);

        return response()->json(['data' => $this->public($task)]);
    }

    public function reorder(
        Request $request,
        OperationalProcess $process,
        OperationalTaskStructureService $structure,
    ): JsonResponse {
        $this->authorize('update', $process);
        RejectClientOfficeId::strip($request);
        $data = $request->validate([
            'order' => ['required', 'array', 'min:1'],
            'order.*.id' => ['required', 'integer'],
            'order.*.sort_order' => ['required', 'integer', 'min:1'],
            'order.*.lock_version' => ['required', 'integer'],
            'justification' => ['nullable', 'string', 'max:2000'],
        ]);
        $structure->reorder($process, $data['order'], $data['justification'] ?? null);

        return response()->json(['data' => ['reordered' => true]]);
    }

    public function bulk(Request $request, OperationalWorkBulkService $service): JsonResponse
    {
        $this->authorize('bulk', OperationalTask::class);
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer'],
            'items.*.lock_version' => ['required', 'integer'],
            'changes' => ['required', 'array'],
            'changes.action' => ['sometimes', 'nullable', 'string', 'in:start,complete,resume,block,claim,assign,set_due_date,set_department'],
            'changes.assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'changes.work_department_id' => ['sometimes', 'nullable', 'integer'],
            'changes.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'changes.status' => ['sometimes', 'nullable', 'string'],
            'changes.reason' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'changes.justification' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        $result = $service->apply($data['items'], $data['changes'], $request->user());

        return response()->json([
            'data' => collect($result['succeeded'])->map(fn (OperationalTask $t) => $this->public($t))->values(),
            'meta' => [
                'succeeded' => count($result['succeeded']),
                'failed' => $result['failed'],
            ],
        ]);
    }

    public function comment(Request $request, OperationalTask $task, CurrentOffice $currentOffice, AuditLogger $audit): JsonResponse
    {
        $this->authorize('comment', $task);
        $data = $request->validate(['body' => ['required', 'string', 'max:5000']]);

        $comment = OperationalComment::query()->create([
            'office_id' => $currentOffice->id(),
            'operational_process_id' => $task->operational_process_id,
            'operational_task_id' => $task->id,
            'author_membership_id' => $currentOffice->membership()?->id,
            'body' => $data['body'],
        ]);

        $audit->record('work.comment.create', 'SUCCESS', $comment, [
            'target' => 'task',
            'task_id' => $task->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
            ],
        ], 201);
    }

    public function uploadEvidence(Request $request, OperationalTask $task, OperationalEvidenceService $service): JsonResponse
    {
        $this->authorize('uploadEvidence', $task);
        $request->validate(['file' => ['required', 'file', 'max:20480']]);

        $evidence = $service->upload($task, $request->file('file'));

        return response()->json(['data' => $this->publicEvidence($evidence)], 201);
    }

    public function downloadEvidence(OperationalTask $task, OperationalTaskEvidence $evidence, OperationalEvidenceService $service): StreamedResponse
    {
        $this->authorize('downloadEvidence', $task);
        if ((int) $evidence->operational_task_id !== (int) $task->id) {
            abort(404);
        }

        return $service->download($evidence);
    }

    public function removeEvidence(Request $request, OperationalTask $task, OperationalTaskEvidence $evidence, OperationalEvidenceService $service): JsonResponse
    {
        $this->authorize('uploadEvidence', $task);
        if ((int) $evidence->operational_task_id !== (int) $task->id) {
            abort(404);
        }
        $data = $request->validate(['reason' => ['required', 'string', 'max:2000']]);
        $service->remove($evidence, $data['reason']);

        return response()->json(['data' => ['removed' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
    private function public(OperationalTask $t, bool $detailed = false): array
    {
        $data = [
            'id' => $t->id,
            'operational_process_id' => $t->operational_process_id,
            'sort_order' => $t->sort_order,
            'title' => $t->title,
            'description' => $t->description,
            'status' => $t->status->value,
            'due_date' => $t->due_date?->format('Y-m-d'),
            'is_required' => $t->is_required,
            'is_critical' => $t->is_critical,
            'requires_evidence' => $t->requires_evidence,
            'block_reason' => $t->block_reason,
            'assignee_membership_id' => $t->assignee_membership_id,
            'work_department_id' => $t->work_department_id,
            'lock_version' => $t->lock_version,
            'started_at' => $t->started_at?->toIso8601String(),
            'completed_at' => $t->completed_at?->toIso8601String(),
        ];

        if ($t->relationLoaded('department') && $t->department) {
            $data['department'] = [
                'id' => $t->department->id,
                'name' => $t->department->name,
                'code' => $t->department->code,
            ];
        }
        if ($t->relationLoaded('assigneeMembership') && $t->assigneeMembership?->user) {
            $data['assignee'] = [
                'membership_id' => $t->assigneeMembership->id,
                'name' => $t->assigneeMembership->user->name,
            ];
        }

        if ($detailed) {
            $riskCalc = new WorkRiskCalculator;
            $today = (new DueDateCalculator)->todayInOffice(
                OfficeTimezone::for(
                    Office::query()->find($t->office_id)
                        ?? new Office(['timezone' => 'America/Sao_Paulo'])
                )
            );
            $process = $t->relationLoaded('process') ? $t->process : null;
            $riskList = $riskCalc->forTask(
                $t->status,
                $t->due_date?->format('Y-m-d'),
                $process?->target_due_date?->format('Y-m-d'),
                $process?->due_date?->format('Y-m-d'),
                (bool) ($process?->subject_to_fine),
                $t->assignee_membership_id,
                $today,
            );
            $data['risks'] = array_map(fn ($r) => $r->value, $riskList);
            $data['effective_due_date'] = $riskCalc->effectiveDueDate(
                $t->due_date?->format('Y-m-d'),
                $process?->target_due_date?->format('Y-m-d'),
                $process?->due_date?->format('Y-m-d'),
            );
            $data['bucket'] = (new QueueBucketResolver)
                ->resolve($t->status, $riskList, $data['effective_due_date'], $today)
                ->value;

            $data['evidences'] = $t->relationLoaded('evidences')
                ? $t->evidences->map(fn (OperationalTaskEvidence $e) => $this->publicEvidence($e))->values()
                : [];
            $data['comments'] = $t->relationLoaded('comments')
                ? $t->comments->map(fn (OperationalComment $c) => [
                    'id' => $c->id,
                    'body' => $c->body,
                    'author_membership_id' => $c->author_membership_id,
                    'created_at' => $c->created_at?->toIso8601String(),
                ])->values()
                : [];
            if ($process) {
                $data['process'] = [
                    'id' => $process->id,
                    'title' => $process->title,
                    'competence' => $process->competence,
                    'status' => $process->status->value,
                    'subject_to_fine' => (bool) $process->subject_to_fine,
                    'due_date' => $process->due_date?->format('Y-m-d'),
                    'client' => $process->client ? [
                        'id' => $process->client->id,
                        'name' => $process->client->display_name ?: $process->client->legal_name,
                    ] : null,
                ];
            }
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function publicEvidence(OperationalTaskEvidence $e): array
    {
        return [
            'id' => $e->id,
            'original_filename' => $e->original_filename,
            'mime_type' => $e->mime_type,
            'byte_size' => $e->byte_size,
            'sha256' => $e->sha256,
            'created_at' => $e->created_at?->toIso8601String(),
            // vault_object_id intencionalmente omitido
        ];
    }
}
