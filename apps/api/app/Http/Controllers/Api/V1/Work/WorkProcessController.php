<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Domain\Work\DueDateCalculator;
use App\Domain\Work\ReferencePeriod;
use App\Domain\Work\WorkRiskCalculator;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Enums\Work\WorkRisk;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\WorkComment;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Services\Work\WorkMonitoringContextRegistry;
use App\Services\Work\WorkProcessBulkService;
use App\Services\Work\WorkProcessService;
use App\Services\Work\WorkTimelineQuery;
use App\Support\CurrentTenant;
use App\Support\Work\RejectClientTenantId;
use App\Support\Work\TenantTimezone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class WorkProcessController extends Controller
{
    public function __construct(
        private readonly WorkMonitoringContextRegistry $monitoringContexts,
    ) {}

    public function index(Request $request, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('viewAny', WorkProcess::class);
        RejectClientTenantId::strip($request);

        $request->validate([
            'status' => ['sometimes', 'nullable', 'string', Rule::enum(ProcessStatus::class)],
        ]);

        if ($request->boolean('without_template') && $request->filled('work_process_template_id')) {
            throw ValidationException::withMessages([
                'without_template' => ['Não combine without_template com work_process_template_id.'],
            ]);
        }

        $includeTasks = $request->has('include_tasks')
            ? $request->boolean('include_tasks')
            : true;

        $perPage = min(max((int) $request->input('per_page', 25), 1), 100);
        $q = WorkProcess::query()
            ->with(array_values(array_filter([
                'client:id,legal_name,display_name,root_cnpj',
                'client.establishments:id,client_id,cnpj,is_headquarters',
                $includeTasks ? 'tasks.department:id,name,code' : null,
                $includeTasks ? 'tasks.assigneeMembership:id,user_id,tenant_id' : null,
                $includeTasks ? 'tasks.assigneeMembership.user:id,name' : null,
                'department:id,name,code',
                'assigneeMembership:id,user_id,tenant_id',
                'assigneeMembership.user:id,name',
            ])))
            ->where('tenant_id', $currentTenant->id());

        if (! $includeTasks) {
            $terminal = [TaskStatus::Concluida->value, TaskStatus::Dispensada->value];
            $q->withCount([
                'tasks',
                'tasks as completed_task_count' => fn ($tasks) => $tasks->whereIn('status', $terminal),
                'tasks as open_task_count' => fn ($tasks) => $tasks->whereNotIn('status', $terminal),
            ]);
        }

        // Listagem padrão exclui arquivados; include_archived=1 traz todos.
        if (! $request->boolean('include_archived')) {
            $q->notArchived();
        }

        if ($request->filled('competence')) {
            $q->where('competence', $request->string('competence')->toString());
        }
        if ($request->filled('reference_period')) {
            $q->where('competence', $request->string('reference_period')->toString());
        }
        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->input('client_id'));
        }
        if ($request->boolean('without_template')) {
            $q->whereNull('work_process_template_id');
        } elseif ($request->filled('work_process_template_id')) {
            $q->where('work_process_template_id', (int) $request->input('work_process_template_id'));
        }
        if ($request->filled('status')) {
            $q->where('status', $request->string('status')->toString());
        }
        if ($request->boolean('active_only')) {
            $q->notArchived()
                ->where('status', '!=', ProcessStatus::Concluido->value);
        }
        if ($request->filled('department_id')) {
            $q->where('work_department_id', (int) $request->input('department_id'));
        }
        if ($request->filled('assignee_membership_id')) {
            $q->where('assignee_membership_id', (int) $request->input('assignee_membership_id'));
        }
        if ($request->filled('q')) {
            $needle = '%'.mb_strtolower($request->string('q')->toString()).'%';
            $q->where(function ($search) use ($needle): void {
                $search->whereRaw('LOWER(title) LIKE ?', [$needle])
                    ->orWhereHas('client', function ($client) use ($needle): void {
                        $client->whereRaw('LOWER(legal_name) LIKE ?', [$needle])
                            ->orWhereRaw('LOWER(COALESCE(display_name, \'\')) LIKE ?', [$needle]);
                    });
            });
        }

        $sort = match ($request->string('sort')->toString()) {
            'title' => 'title',
            'competence' => 'competence',
            'status' => 'status',
            'due_date' => 'due_date',
            default => 'id',
        };
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        $q->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $q->orderBy('id', $direction);
        }

        $paginator = $q->paginate($perPage);
        $today = (new DueDateCalculator)->todayInTenant(TenantTimezone::for($currentTenant->tenant()));

        return response()->json([
            'data' => collect($paginator->items())->map(
                fn (WorkProcess $p) => $this->public($p, false, $today, $includeTasks)
            ),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function show(WorkProcess $process, CurrentTenant $currentTenant): JsonResponse
    {
        $this->authorize('view', $process);
        $process->load(['client.establishments', 'tasks.evidences', 'tasks.assigneeMembership.user', 'tasks.department', 'department', 'assigneeMembership.user', 'comments']);
        $today = (new DueDateCalculator)->todayInTenant(TenantTimezone::for($currentTenant->tenant()));

        return response()->json(['data' => $this->public($process, detailed: true, today: $today)]);
    }

    public function store(Request $request, WorkProcessService $service): JsonResponse
    {
        $this->authorize('create', WorkProcess::class);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'monitoring_module_key' => ['nullable', 'string', Rule::in($this->monitoringContexts->keys())],
            'competence' => ['required', 'string', 'max:16'],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'subject_to_fine' => ['sometimes', 'boolean'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
            'tasks' => ['required', 'array', 'min:1'],
            'tasks.*.title' => ['required', 'string', 'max:200'],
            'tasks.*.description' => ['nullable', 'string'],
            'tasks.*.sort_order' => ['sometimes', 'integer', 'min:1'],
            'tasks.*.due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'tasks.*.work_department_id' => ['nullable', 'integer'],
            'tasks.*.assignee_membership_id' => ['nullable', 'integer'],
            'tasks.*.is_required' => ['sometimes', 'boolean'],
            'tasks.*.is_critical' => ['sometimes', 'boolean'],
            'tasks.*.requires_evidence' => ['sometimes', 'boolean'],
        ]);

        $process = $service->createManual($data, $data['tasks']);

        return response()->json(['data' => $this->public($process, detailed: true)], 201);
    }

    public function update(Request $request, WorkProcess $process, WorkProcessService $service): JsonResponse
    {
        $this->authorize('update', $process);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
            'title' => ['sometimes', 'string', 'max:200'],
            'description' => ['nullable', 'string'],
            'monitoring_module_key' => ['nullable', 'string', Rule::in($this->monitoringContexts->keys())],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
            'target_due_date' => ['nullable', 'date_format:Y-m-d'],
            'subject_to_fine' => ['sometimes', 'boolean'],
            'work_department_id' => ['nullable', 'integer'],
            'assignee_membership_id' => ['nullable', 'integer'],
        ]);

        $process = $service->update($process, (int) $data['lock_version'], $data);

        return response()->json(['data' => $this->public($process, detailed: true)]);
    }

    public function archive(Request $request, WorkProcess $process, WorkProcessService $service): JsonResponse
    {
        $this->authorize('archive', $process);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);

        $process = $service->archive($process, (int) $data['lock_version']);

        return response()->json(['data' => $this->public($process)]);
    }

    public function bulk(Request $request, WorkProcessBulkService $service): JsonResponse
    {
        $this->authorize('bulk', WorkProcess::class);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.id' => ['required', 'integer'],
            'items.*.lock_version' => ['required', 'integer'],
            'changes' => ['required', 'array'],
            'changes.action' => ['required', 'string', 'in:archive,assign,set_department,set_due_date'],
            'changes.assignee_membership_id' => ['sometimes', 'nullable', 'integer'],
            'changes.work_department_id' => ['sometimes', 'nullable', 'integer'],
            'changes.due_date' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
        ]);

        $result = $service->apply($data['items'], $data['changes'], $request->user());

        return response()->json([
            'data' => collect($result['succeeded'])->map(fn (WorkProcess $p) => $this->public($p))->values(),
            'meta' => [
                'succeeded' => count($result['succeeded']),
                'failed' => $result['failed'],
            ],
        ]);
    }

    public function comment(Request $request, WorkProcess $process, CurrentTenant $currentTenant, AuditLogger $audit): JsonResponse
    {
        $this->authorize('comment', $process);
        RejectClientTenantId::strip($request);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $comment = WorkComment::query()->create([
            'tenant_id' => $currentTenant->id(),
            'work_process_id' => $process->id,
            'work_task_id' => null,
            'author_membership_id' => $currentTenant->realMembership()?->id,
            'body' => $data['body'],
        ]);

        $audit->record('work.comment.create', 'SUCCESS', $comment, [
            'target' => 'process',
            'process_id' => $process->id,
        ]);

        return response()->json([
            'data' => [
                'id' => $comment->id,
                'body' => $comment->body,
                'created_at' => $comment->created_at?->toIso8601String(),
                'author_membership_id' => $comment->author_membership_id,
            ],
        ], 201);
    }

    public function timeline(WorkProcess $process, WorkTimelineQuery $timeline): JsonResponse
    {
        $this->authorize('view', $process);

        return response()->json([
            'data' => $timeline->forProcess($process),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function public(
        WorkProcess $p,
        bool $detailed = false,
        ?string $today = null,
        bool $includeTasks = true,
    ): array {
        $today ??= (new DueDateCalculator)->todayInTenant('America/Sao_Paulo');
        $riskCalc = new WorkRiskCalculator;

        $taskCount = null;
        $completedCount = null;
        $openCount = null;
        $progressPercent = null;
        $risks = [];

        if ($p->relationLoaded('tasks')) {
            $taskCount = $p->tasks->count();
            $completedCount = $p->tasks->filter(
                fn (WorkTask $t) => in_array($t->status, [TaskStatus::Concluida, TaskStatus::Dispensada], true)
            )->count();
            $openCount = $p->tasks->filter(fn (WorkTask $t) => ! $t->status->isTerminal())->count();
            $progressPercent = $taskCount > 0 ? (int) round(($completedCount / $taskCount) * 100) : 0;

            foreach ($p->tasks as $t) {
                if ($t->status->isTerminal()) {
                    continue;
                }
                foreach ($riskCalc->forTask(
                    $t->status,
                    $t->due_date?->format('Y-m-d'),
                    $p->target_due_date?->format('Y-m-d'),
                    $p->due_date?->format('Y-m-d'),
                    (bool) $p->subject_to_fine,
                    $t->assignee_membership_id,
                    $today,
                ) as $r) {
                    $risks[$r->value] = true;
                }
            }
        } elseif (isset($p->tasks_count) || isset($p->completed_task_count) || isset($p->open_task_count)) {
            $taskCount = (int) ($p->tasks_count ?? 0);
            $completedCount = (int) ($p->completed_task_count ?? 0);
            $openCount = (int) ($p->open_task_count ?? 0);
            $progressPercent = $taskCount > 0 ? (int) round(($completedCount / $taskCount) * 100) : 0;
        }

        $referencePeriod = $this->referencePeriodPayload($p);

        $data = [
            'id' => $p->id,
            'title' => $p->title,
            'description' => $p->description,
            'monitoring_module_key' => $p->monitoring_module_key,
            'competence' => $p->competence,
            'reference_period' => $referencePeriod,
            'origin' => $p->origin->value,
            'status' => $p->status->value,
            'archived_at' => $p->archived_at?->toIso8601String(),
            'is_archived' => $p->archived_at !== null,
            'due_date' => $p->due_date?->format('Y-m-d'),
            'target_due_date' => $p->target_due_date?->format('Y-m-d'),
            'subject_to_fine' => $p->subject_to_fine,
            'work_department_id' => $p->work_department_id,
            'assignee_membership_id' => $p->assignee_membership_id,
            'client_id' => $p->client_id,
            'work_process_template_id' => $p->work_process_template_id,
            'lock_version' => $p->lock_version,
            'client' => $p->relationLoaded('client') && $p->client ? [
                'id' => $p->client->id,
                'name' => $p->client->display_name ?: $p->client->legal_name,
                'cnpj_masked' => $this->clientCnpjMasked($p->client),
            ] : null,
            'links' => $p->client_id ? [
                'client' => "/clients/{$p->client_id}/cadastro",
                'monitoring' => "/monitoring/clients/{$p->client_id}",
            ] : null,
            'monitoring_context' => $this->monitoringContexts->forClient(
                $p->monitoring_module_key,
                (int) $p->client_id,
            ),
            'department' => $p->relationLoaded('department') && $p->department ? [
                'id' => $p->department->id,
                'name' => $p->department->name,
                'code' => $p->department->code,
            ] : null,
            'assignee' => $p->relationLoaded('assigneeMembership') && $p->assigneeMembership?->user ? [
                'membership_id' => $p->assigneeMembership->id,
                'name' => $p->assigneeMembership->user->name,
            ] : null,
            'task_count' => $taskCount,
            'completed_task_count' => $completedCount,
            'open_task_count' => $openCount,
            'progress_percent' => $progressPercent,
            'risks' => array_keys($risks),
        ];

        if ($includeTasks && $p->relationLoaded('tasks')) {
            $data['tasks'] = $p->tasks->map(function (WorkTask $t) use ($p, $riskCalc, $today) {
                $taskRisks = $riskCalc->forTask(
                    $t->status,
                    $t->due_date?->format('Y-m-d'),
                    $p->target_due_date?->format('Y-m-d'),
                    $p->due_date?->format('Y-m-d'),
                    (bool) $p->subject_to_fine,
                    $t->assignee_membership_id,
                    $today,
                );

                return [
                    'id' => $t->id,
                    'sort_order' => $t->sort_order,
                    'title' => $t->title,
                    'description' => $t->description,
                    'status' => $t->status->value,
                    'due_date' => $t->due_date?->format('Y-m-d'),
                    'target_due_date' => $t->target_due_date?->format('Y-m-d'),
                    'effective_due_date' => $riskCalc->effectiveDueDate(
                        $t->due_date?->format('Y-m-d'),
                        $p->target_due_date?->format('Y-m-d'),
                        $p->due_date?->format('Y-m-d'),
                    ),
                    'is_required' => $t->is_required,
                    'is_critical' => $t->is_critical,
                    'requires_evidence' => $t->requires_evidence,
                    'block_reason' => $t->block_reason,
                    'assignee_membership_id' => $t->assignee_membership_id,
                    'work_department_id' => $t->work_department_id,
                    'lock_version' => $t->lock_version,
                    'risks' => array_map(fn (WorkRisk $r) => $r->value, $taskRisks),
                    'department' => $t->relationLoaded('department') && $t->department ? [
                        'id' => $t->department->id,
                        'name' => $t->department->name,
                        'code' => $t->department->code,
                    ] : null,
                    'assignee' => $t->relationLoaded('assigneeMembership') && $t->assigneeMembership?->user ? [
                        'membership_id' => $t->assigneeMembership->id,
                        'name' => $t->assigneeMembership->user->name,
                    ] : null,
                    'evidence_count' => $t->relationLoaded('evidences') ? $t->evidences->count() : null,
                ];
            })->values();

        }

        if ($detailed && $p->relationLoaded('comments')) {
            $data['comments'] = $p->comments->map(fn (WorkComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author_membership_id' => $c->author_membership_id,
                'created_at' => $c->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    /**
     * @return array{type: string, key: string, start: string, end: string}|null
     */
    private function referencePeriodPayload(WorkProcess $p): ?array
    {
        if ($p->reference_period_type && $p->reference_period_start && $p->reference_period_end) {
            return [
                'type' => (string) $p->reference_period_type,
                'key' => (string) $p->competence,
                'start' => $p->reference_period_start->format('Y-m-d'),
                'end' => $p->reference_period_end->format('Y-m-d'),
            ];
        }

        try {
            return ReferencePeriod::fromString((string) $p->competence)->toArray();
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    private function clientCnpjMasked(Client $client): ?string
    {
        $cnpj = null;
        if ($client->relationLoaded('establishments')) {
            $cnpj = $client->establishments
                ->sortByDesc('is_headquarters')
                ->first()?->cnpj;
        }
        $digits = preg_replace('/\D+/', '', (string) $cnpj) ?? '';
        if (strlen($digits) !== 14) {
            return null;
        }

        return substr($digits, 0, 2).'.'.substr($digits, 2, 3).'.'.substr($digits, 5, 3)
            .'/'.substr($digits, 8, 4).'-'.substr($digits, 12, 2);
    }
}
