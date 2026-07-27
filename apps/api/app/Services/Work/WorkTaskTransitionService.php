<?php

namespace App\Services\Work;

use App\Domain\Work\ProcessStateCalculator;
use App\Enums\Work\ProcessStatus;
use App\Enums\Work\TaskStatus;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Models\WorkTaskEvidence;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentTenant;
use App\Support\Work\OptimisticLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Único caminho de transição de lifecycle de tarefas.
 */
final class WorkTaskTransitionService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly ProcessStateCalculator $processState,
        private readonly AuditLogger $audit,
    ) {}

    public function start(WorkTask $task, int $lockVersion): WorkTask
    {
        return $this->transition($task, $lockVersion, TaskStatus::EmProgresso, requireReason: false);
    }

    public function block(WorkTask $task, int $lockVersion, string $reason): WorkTask
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages([
                'reason' => ['Motivo de impedimento é obrigatório.'],
            ]);
        }

        return $this->transition($task, $lockVersion, TaskStatus::Impedida, requireReason: true, reason: $reason);
    }

    public function resume(WorkTask $task, int $lockVersion): WorkTask
    {
        return $this->transition($task, $lockVersion, TaskStatus::EmProgresso, requireReason: false, clearBlock: true);
    }

    public function complete(WorkTask $task, int $lockVersion): WorkTask
    {
        if ($task->requires_evidence) {
            $hasEvidence = WorkTaskEvidence::query()
                ->where('work_task_id', $task->id)
                ->where('tenant_id', $task->tenant_id)
                ->whereNull('removed_at')
                ->exists();
            if (! $hasEvidence) {
                throw ValidationException::withMessages([
                    'evidence' => ['Esta tarefa exige ao menos uma evidência antes da conclusão.'],
                ]);
            }
        }

        return $this->transition($task, $lockVersion, TaskStatus::Concluida, requireReason: false);
    }

    public function dispense(WorkTask $task, int $lockVersion, string $justification): WorkTask
    {
        $justification = trim($justification);
        if ($justification === '') {
            throw ValidationException::withMessages([
                'justification' => ['Justificativa de dispensa é obrigatória.'],
            ]);
        }

        return $this->transition(
            $task,
            $lockVersion,
            TaskStatus::Dispensada,
            requireReason: true,
            reason: $justification,
            auditAction: 'work.task.dispense',
        );
    }

    public function reopen(WorkTask $task, int $lockVersion, string $justification): WorkTask
    {
        $justification = trim($justification);
        if ($justification === '') {
            throw ValidationException::withMessages([
                'justification' => ['Justificativa de reabertura é obrigatória.'],
            ]);
        }

        if (! $task->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => ['Somente tarefas concluídas ou dispensadas podem ser reabertas.'],
            ]);
        }

        return $this->transition(
            $task,
            $lockVersion,
            TaskStatus::AFazer,
            requireReason: true,
            reason: $justification,
            auditAction: 'work.task.reopen',
            forceFromTerminal: true,
        );
    }

    private function transition(
        WorkTask $task,
        int $lockVersion,
        TaskStatus $to,
        bool $requireReason,
        ?string $reason = null,
        bool $clearBlock = false,
        string $auditAction = 'work.task.transition',
        bool $forceFromTerminal = false,
    ): WorkTask {
        OptimisticLock::assert($task, $lockVersion, 'work_task');

        $from = $task->status;
        if (! $forceFromTerminal) {
            $this->assertAllowed($from, $to);
        }

        $membershipId = $this->currentTenant->realMembership()?->id;
        $now = now();

        return DB::transaction(function () use (
            $task, $lockVersion, $to, $from, $reason, $clearBlock, $auditAction, $membershipId, $now,
        ): WorkTask {
            $attrs = [
                'status' => $to->value,
            ];

            if ($to === TaskStatus::EmProgresso) {
                if ($task->started_at === null) {
                    $attrs['started_at'] = $now;
                    $attrs['started_by_membership_id'] = $membershipId;
                }
                if ($clearBlock || $from === TaskStatus::Impedida) {
                    $attrs['block_reason'] = null;
                }
                $attrs['completed_at'] = null;
                $attrs['completed_by_membership_id'] = null;
            }

            if ($to === TaskStatus::Impedida) {
                $attrs['block_reason'] = $reason;
            }

            if ($to === TaskStatus::Concluida || $to === TaskStatus::Dispensada) {
                $attrs['completed_at'] = $now;
                $attrs['completed_by_membership_id'] = $membershipId;
                $attrs['block_reason'] = $to === TaskStatus::Dispensada ? $reason : $task->block_reason;
            }

            if ($to === TaskStatus::AFazer) {
                $attrs['started_at'] = null;
                $attrs['started_by_membership_id'] = null;
                $attrs['completed_at'] = null;
                $attrs['completed_by_membership_id'] = null;
                $attrs['block_reason'] = null;
            }

            OptimisticLock::updateOrConflict($task, $lockVersion, $attrs, 'work_task');
            $task->refresh();

            $this->recalculateProcess($task->process()->firstOrFail());

            $this->audit->record($auditAction, 'SUCCESS', $task, [
                'from' => $from->value,
                'to' => $to->value,
                'reason' => $reason,
                'process_id' => $task->work_process_id,
            ]);

            return $task;
        });
    }

    private function assertAllowed(TaskStatus $from, TaskStatus $to): void
    {
        $allowed = match ($from) {
            TaskStatus::AFazer => [TaskStatus::EmProgresso, TaskStatus::Impedida, TaskStatus::Concluida, TaskStatus::Dispensada],
            TaskStatus::EmProgresso => [TaskStatus::Impedida, TaskStatus::Concluida, TaskStatus::Dispensada],
            TaskStatus::Impedida => [TaskStatus::EmProgresso, TaskStatus::Concluida, TaskStatus::Dispensada],
            TaskStatus::Concluida, TaskStatus::Dispensada => [],
        };

        if (! in_array($to, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Transição de {$from->value} para {$to->value} não permitida."],
            ]);
        }
    }

    public function recalculateProcess(WorkProcess $process): void
    {
        $tasks = $process->tasks()->get()->map(fn (WorkTask $t) => [
            'status' => $t->status,
            'is_required' => $t->is_required,
            'is_critical' => $t->is_critical,
        ])->all();

        $derived = $this->processState->derive($tasks, $process->status);
        $attrs = ['status' => $derived->value];

        if ($derived === ProcessStatus::EmProgresso && $process->started_at === null) {
            $attrs['started_at'] = now();
        }
        if ($derived === ProcessStatus::Concluido && $process->completed_at === null) {
            $attrs['completed_at'] = now();
        }
        if ($derived !== ProcessStatus::Concluido) {
            $attrs['completed_at'] = null;
        }

        $process->forceFill($attrs)->save();
    }
}
