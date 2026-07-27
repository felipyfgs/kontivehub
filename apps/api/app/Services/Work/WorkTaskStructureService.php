<?php

namespace App\Services\Work;

use App\Enums\TenantPermission;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Criação/edição/reordenação estrutural de tarefas.
 * OPERATOR só antes do processo iniciar; depois apenas ADMIN com justificativa.
 */
final class WorkTaskStructureService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MembershipResolver $memberships,
        private readonly WorkTaskTransitionService $transitions,
        private readonly AuditLogger $audit,
        private readonly TenantAuthorization $authorization,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function addTask(WorkProcess $process, array $data): WorkTask
    {
        $this->assertCanMutateStructure($process, $data['justification'] ?? null);

        if (! empty($data['work_department_id'])) {
            $this->memberships->requireActiveDepartment((int) $data['work_department_id']);
        }
        if (! empty($data['assignee_membership_id'])) {
            $this->memberships->requireActiveMembership((int) $data['assignee_membership_id']);
        }

        $task = DB::transaction(function () use ($process, $data): WorkTask {
            // Serializa criações concorrentes no mesmo processo (unique sort_order).
            $locked = WorkProcess::query()
                ->whereKey($process->id)
                ->lockForUpdate()
                ->firstOrFail();

            $max = (int) $locked->tasks()->max('sort_order');

            return WorkTask::query()->create([
                'tenant_id' => $locked->tenant_id,
                'work_process_id' => $locked->id,
                'sort_order' => $data['sort_order'] ?? ($max + 1),
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => TaskStatus::AFazer,
                'due_date' => $data['due_date'] ?? null,
                'work_department_id' => $data['work_department_id'] ?? $locked->work_department_id,
                'assignee_membership_id' => $data['assignee_membership_id'] ?? null,
                'is_required' => $data['is_required'] ?? true,
                'is_critical' => $data['is_critical'] ?? false,
                'requires_evidence' => $data['requires_evidence'] ?? false,
                'lock_version' => 1,
            ]);
        });

        $this->transitions->recalculateProcess($process->fresh());
        $this->audit->record('work.task.structure.create', 'SUCCESS', $task, [
            'process_id' => $process->id,
            'justification' => $data['justification'] ?? null,
        ]);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateTask(WorkTask $task, int $lockVersion, array $data): WorkTask
    {
        $process = $task->process()->firstOrFail();
        $this->assertCanMutateStructure($process, $data['justification'] ?? null);
        OptimisticLock::assert($task, $lockVersion, 'work_task');

        // Null limpa assignee/dept; ID não-nulo deve pertencer ao escritório ativo.
        if (array_key_exists('work_department_id', $data) && $data['work_department_id'] !== null) {
            $this->memberships->requireActiveDepartment((int) $data['work_department_id']);
        }
        if (array_key_exists('assignee_membership_id', $data) && $data['assignee_membership_id'] !== null) {
            $this->memberships->requireActiveMembership((int) $data['assignee_membership_id']);
        }

        $attrs = collect($data)->only([
            'title', 'description', 'due_date', 'target_due_date',
            'work_department_id', 'assignee_membership_id',
            'is_required', 'is_critical', 'requires_evidence',
        ])->all();

        OptimisticLock::updateOrConflict($task, $lockVersion, $attrs, 'work_task');
        $this->audit->record('work.task.structure.update', 'SUCCESS', $task, [
            'fields' => array_keys($attrs),
            'justification' => $data['justification'] ?? null,
        ]);

        return $task->fresh();
    }

    /**
     * @param  list<array{id: int, sort_order: int, lock_version: int}>  $order
     */
    public function reorder(WorkProcess $process, array $order, ?string $justification = null): void
    {
        $this->assertCanMutateStructure($process, $justification);

        DB::transaction(function () use ($process, $order): void {
            $tasks = $process->tasks()->get()->keyBy('id');
            foreach ($order as $row) {
                $task = $tasks->get((int) $row['id']);
                if ($task === null || (int) $task->tenant_id !== (int) $process->tenant_id) {
                    throw ValidationException::withMessages([
                        'order' => ['Tarefa inválida no reordenamento.'],
                    ]);
                }
                OptimisticLock::updateOrConflict(
                    $task,
                    (int) $row['lock_version'],
                    ['sort_order' => (int) $row['sort_order']],
                    'work_task',
                );
            }
        });

        $this->audit->record('work.task.structure.reorder', 'SUCCESS', $process, [
            'count' => count($order),
            'justification' => $justification,
        ]);
    }

    private function assertCanMutateStructure(WorkProcess $process, ?string $justification): void
    {
        $actor = $this->currentTenant->actor();
        $started = $process->status !== ProcessStatus::AFazer
            || $process->tasks()->where('status', '!=', TaskStatus::AFazer->value)->exists();

        if (! $started) {
            if ($actor === null
                || ! $this->authorization->allows($actor, TenantPermission::WorkProcessesCreate, $process)) {
                abort(403);
            }

            return;
        }

        // Após início: capacidade administrativa específica, com justificativa.
        if ($actor === null
            || ! $this->authorization->allows($actor, TenantPermission::WorkAdminister, $process)) {
            throw ValidationException::withMessages([
                'structure' => ['Após o início do processo, apenas quem administra o Work pode alterar a estrutura.'],
            ]);
        }

        if (trim((string) $justification) === '') {
            throw ValidationException::withMessages([
                'justification' => ['Justificativa obrigatória para alteração estrutural após início.'],
            ]);
        }
    }
}
