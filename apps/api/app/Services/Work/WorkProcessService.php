<?php

namespace App\Services\Work;

use App\Domain\Work\ReferencePeriod;
use App\DTO\Work\WorkProcessCreationData;
use App\DTO\Work\WorkProcessUpdateData;
use App\Enums\Work\ProcessOrigin;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\Client;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Criação manual e mutações de processo.
 *
 * Coordenador = assignee_membership_id do processo (não herdado pelas tarefas).
 * due_date = prazo legal; target_due_date = meta interna.
 */
final class WorkProcessService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MembershipResolver $memberships,
        private readonly WorkTaskTransitionService $transitions,
        private readonly AuditLogger $audit,
    ) {}

    public function createManual(WorkProcessCreationData $input): WorkProcess
    {
        $data = $input->attributes;
        $tasks = $input->tasks;
        $tenantId = $this->currentTenant->id();

        if ($tasks === []) {
            throw ValidationException::withMessages([
                'tasks' => ['Processo deve ter ao menos uma tarefa.'],
            ]);
        }

        try {
            $period = ReferencePeriod::fromString((string) ($data['competence'] ?? $data['reference_period'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([
                'competence' => [$e->getMessage()],
            ]);
        }

        return DB::transaction(function () use ($tenantId, $data, $tasks, $period): WorkProcess {
            $client = Client::query()
                ->where('tenant_id', $tenantId)
                ->where('id', (int) $data['client_id'])
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();
            if ($client === null) {
                throw ValidationException::withMessages([
                    'client_id' => ['Cliente inválido ou inativo neste escritório.'],
                ]);
            }
            if (! empty($data['work_department_id'])) {
                $this->memberships->requireActiveDepartment(
                    (int) $data['work_department_id'],
                );
            }
            if (! empty($data['assignee_membership_id'])) {
                $this->memberships->requireActiveMembership(
                    (int) $data['assignee_membership_id'],
                );
            }

            $process = WorkProcess::query()->create([
                'tenant_id' => $tenantId,
                'client_id' => $data['client_id'],
                'origin' => ProcessOrigin::Manual,
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'monitoring_module_key' => $data['monitoring_module_key'] ?? null,
                'competence' => $period->value(),
                'reference_period_type' => $period->type->value,
                'reference_period_start' => $period->startDate(),
                'reference_period_end' => $period->endDate(),
                'due_date' => $data['due_date'] ?? null,
                'target_due_date' => $data['target_due_date'] ?? null,
                'subject_to_fine' => (bool) ($data['subject_to_fine'] ?? false),
                'work_department_id' => $data['work_department_id'] ?? null,
                'assignee_membership_id' => $data['assignee_membership_id'] ?? null,
                'status' => ProcessStatus::AFazer,
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);

            $order = 1;
            foreach ($tasks as $t) {
                if (! empty($t['work_department_id'])) {
                    $this->memberships->requireActiveDepartment((int) $t['work_department_id']);
                }
                if (! empty($t['assignee_membership_id'])) {
                    $this->memberships->requireActiveMembership((int) $t['assignee_membership_id']);
                }

                // Executor da tarefa: NÃO herda o coordenador do processo.
                WorkTask::query()->create([
                    'tenant_id' => $tenantId,
                    'work_process_id' => $process->id,
                    'sort_order' => $t['sort_order'] ?? $order,
                    'title' => $t['title'],
                    'description' => $t['description'] ?? null,
                    'status' => TaskStatus::AFazer,
                    'due_date' => $t['due_date'] ?? null,
                    'target_due_date' => $t['target_due_date'] ?? null,
                    'work_department_id' => $t['work_department_id'] ?? $data['work_department_id'] ?? null,
                    'assignee_membership_id' => $t['assignee_membership_id'] ?? null,
                    'is_required' => (bool) ($t['is_required'] ?? true),
                    'is_critical' => (bool) ($t['is_critical'] ?? false),
                    'requires_evidence' => (bool) ($t['requires_evidence'] ?? false),
                    'lock_version' => 1,
                ]);
                $order++;
            }

            $this->transitions->recalculateProcess($process->fresh(['tasks']));

            $this->audit->record('work.process.create', 'SUCCESS', $process, [
                'origin' => ProcessOrigin::Manual->value,
                'tasks' => count($tasks),
                'reference_period' => $period->value(),
            ]);

            return $process->load('tasks');
        });
    }

    public function update(
        WorkProcess $process,
        WorkProcessUpdateData $input,
    ): WorkProcess {
        return DB::transaction(function () use ($process, $input): WorkProcess {
            $data = $input->attributes;
            OptimisticLock::assert(
                $process,
                $input->lockVersion,
                'work_process',
            );

            if (! empty($data['work_department_id'])) {
                $this->memberships->requireActiveDepartment(
                    (int) $data['work_department_id'],
                );
            }
            if (array_key_exists('assignee_membership_id', $data)
                && $data['assignee_membership_id'] !== null) {
                $this->memberships->requireActiveMembership(
                    (int) $data['assignee_membership_id'],
                );
            }

            $allowed = collect($data)->only([
                'title', 'description', 'due_date', 'target_due_date',
                'monitoring_module_key',
                'subject_to_fine', 'work_department_id', 'assignee_membership_id',
            ])->all();

            OptimisticLock::updateOrConflict(
                $process,
                $input->lockVersion,
                $allowed,
                'work_process',
            );

            $this->audit->record('work.process.update', 'SUCCESS', $process, [
                'fields' => array_keys($allowed),
            ]);

            return $process->fresh(['tasks']);
        });
    }

    public function archive(WorkProcess $process, int $lockVersion): WorkProcess
    {
        return DB::transaction(function () use ($process, $lockVersion): WorkProcess {
            OptimisticLock::assert($process, $lockVersion, 'work_process');

            if ($process->archived_at !== null) {
                throw ValidationException::withMessages([
                    'process' => ['Processo já está arquivado.'],
                ]);
            }

            if ($process->status !== ProcessStatus::Concluido) {
                throw ValidationException::withMessages([
                    'status' => ['Somente processos com status terminal (CONCLUIDO) podem ser arquivados.'],
                ]);
            }

            OptimisticLock::updateOrConflict($process, $lockVersion, [
                'archived_at' => now(),
            ], 'work_process');

            $this->audit->record(
                'work.process.archive',
                'SUCCESS',
                $process,
            );

            return $process->fresh();
        });
    }

    public function claimTask(WorkTask $task, int $lockVersion): WorkTask
    {
        OptimisticLock::assert($task, $lockVersion, 'work_task');
        $membershipId = $this->currentTenant->realMembership()?->id;
        if ($membershipId === null) {
            abort(403);
        }

        OptimisticLock::updateOrConflict($task, $lockVersion, [
            'assignee_membership_id' => $membershipId,
        ], 'work_task');

        $this->audit->record('work.task.claim', 'SUCCESS', $task);

        return $task->fresh();
    }
}
