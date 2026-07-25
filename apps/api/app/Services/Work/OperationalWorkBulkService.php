<?php

namespace App\Services\Work;

use App\Models\OperationalTask;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use App\Support\Work\OptimisticLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operações em lote de tarefas com relatório parcial e auth por item.
 */
final class OperationalWorkBulkService
{
    public const MAX_ITEMS = 100;

    public const ACTIONS = [
        'start',
        'complete',
        'resume',
        'block',
        'claim',
        'assign',
        'set_due_date',
        'set_department',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MembershipResolver $memberships,
        private readonly OperationalTaskTransitionService $transitions,
        private readonly OperationalProcessService $processes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{id: int, lock_version: int}>  $items
     * @param  array{
     *   action?: string|null,
     *   assignee_membership_id?: int|null,
     *   work_department_id?: int|null,
     *   due_date?: string|null,
     *   status?: string|null,
     *   reason?: string|null,
     *   justification?: string|null
     * }  $changes
     * @return array{succeeded: list<OperationalTask>, failed: list<array{id: int, message: string}>}
     */
    public function apply(array $items, array $changes, User $actor): array
    {
        if (count($items) === 0) {
            throw ValidationException::withMessages(['items' => ['Lote vazio.']]);
        }
        if (count($items) > self::MAX_ITEMS) {
            throw ValidationException::withMessages([
                'items' => ['Lote excede o limite de '.self::MAX_ITEMS.' itens.'],
            ]);
        }

        $action = $this->resolveAction($changes);
        $officeId = $this->currentOffice->id();
        $ids = array_map(fn ($i) => (int) $i['id'], $items);
        $versionMap = [];
        foreach ($items as $i) {
            $versionMap[(int) $i['id']] = (int) $i['lock_version'];
        }

        $tasks = OperationalTask::query()
            ->where('office_id', $officeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if (array_key_exists('assignee_membership_id', $changes) && $changes['assignee_membership_id'] !== null) {
            $this->memberships->requireActiveMembership((int) $changes['assignee_membership_id']);
        }
        if (array_key_exists('work_department_id', $changes) && $changes['work_department_id'] !== null) {
            $this->memberships->requireActiveDepartment((int) $changes['work_department_id']);
        }

        $correlation = $this->audit->correlationId();
        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $task = $tasks->get($id);
            if ($task === null) {
                $failed[] = ['id' => $id, 'message' => 'Tarefa inválida ou de outro escritório.'];

                continue;
            }

            try {
                OptimisticLock::assert($task, $versionMap[$id], 'operational_task');
                $this->applyOne($actor, $task, $versionMap[$id], $action, $changes);
                $task->refresh();
                $this->audit->record('work.task.bulk', 'SUCCESS', $task, [
                    'correlation' => $correlation,
                    'action' => $action,
                ]);
                $succeeded[] = $task;
            } catch (AuthorizationException) {
                $failed[] = ['id' => $id, 'message' => 'Sem permissão para esta tarefa.'];
            } catch (ValidationException $e) {
                $message = collect($e->errors())->flatten()->first() ?: 'Falha de validação.';
                $failed[] = ['id' => $id, 'message' => (string) $message];
            } catch (Throwable $e) {
                $failed[] = ['id' => $id, 'message' => $e->getMessage() ?: 'Falha ao processar item.'];
            }
        }

        return ['succeeded' => $succeeded, 'failed' => $failed];
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function resolveAction(array $changes): string
    {
        if (! empty($changes['action']) && is_string($changes['action'])) {
            $action = $changes['action'];
            if (! in_array($action, self::ACTIONS, true)) {
                throw ValidationException::withMessages(['changes.action' => ['Ação de lote inválida.']]);
            }

            return $action;
        }

        // Compat legado: status=EM_PROGRESSO ⇒ start; campos de atributo sem action.
        if (($changes['status'] ?? null) === 'EM_PROGRESSO') {
            return 'start';
        }
        if (array_key_exists('assignee_membership_id', $changes)) {
            return 'assign';
        }
        if (array_key_exists('due_date', $changes)) {
            return 'set_due_date';
        }
        if (array_key_exists('work_department_id', $changes)) {
            return 'set_department';
        }

        throw ValidationException::withMessages(['changes.action' => ['Informe a ação do lote.']]);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function applyOne(User $actor, OperationalTask $task, int $lockVersion, string $action, array $changes): void
    {
        match ($action) {
            'start' => $this->gated($actor, 'transition', $task, fn () => $this->transitions->start($task, $lockVersion)),
            'complete' => $this->gated($actor, 'transition', $task, fn () => $this->transitions->complete($task, $lockVersion)),
            'resume' => $this->gated($actor, 'transition', $task, fn () => $this->transitions->resume($task, $lockVersion)),
            'block' => $this->gated($actor, 'transition', $task, function () use ($task, $lockVersion, $changes): void {
                $reason = trim((string) ($changes['reason'] ?? ''));
                $this->transitions->block($task, $lockVersion, $reason);
            }),
            'claim' => $this->gated($actor, 'claim', $task, fn () => $this->processes->claimTask($task, $lockVersion)),
            'assign' => $this->gated($actor, 'assign', $task, function () use ($task, $lockVersion, $changes): void {
                if (! array_key_exists('assignee_membership_id', $changes)) {
                    throw ValidationException::withMessages([
                        'changes.assignee_membership_id' => ['Informe o responsável.'],
                    ]);
                }
                OptimisticLock::updateOrConflict($task, $lockVersion, [
                    'assignee_membership_id' => $changes['assignee_membership_id'],
                ], 'operational_task');
            }),
            'set_due_date' => $this->gated($actor, 'assign', $task, function () use ($task, $lockVersion, $changes): void {
                if (! array_key_exists('due_date', $changes)) {
                    throw ValidationException::withMessages([
                        'changes.due_date' => ['Informe o prazo.'],
                    ]);
                }
                OptimisticLock::updateOrConflict($task, $lockVersion, [
                    'due_date' => $changes['due_date'],
                ], 'operational_task');
            }),
            'set_department' => $this->gated($actor, 'assign', $task, function () use ($task, $lockVersion, $changes): void {
                if (! array_key_exists('work_department_id', $changes)) {
                    throw ValidationException::withMessages([
                        'changes.work_department_id' => ['Informe o departamento.'],
                    ]);
                }
                OptimisticLock::updateOrConflict($task, $lockVersion, [
                    'work_department_id' => $changes['work_department_id'],
                ], 'operational_task');
            }),
            default => throw ValidationException::withMessages(['changes.action' => ['Ação inválida.']]),
        };
    }

    /**
     * @param  callable(): mixed  $callback
     */
    private function gated(User $actor, string $ability, OperationalTask $task, callable $callback): void
    {
        if (! Gate::forUser($actor)->allows($ability, $task)) {
            throw new AuthorizationException;
        }
        $callback();
    }
}
