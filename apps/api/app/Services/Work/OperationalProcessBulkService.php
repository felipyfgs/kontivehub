<?php

namespace App\Services\Work;

use App\Models\OperationalProcess;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use App\Support\Work\OptimisticLock;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Operações em lote de processos (archive / assign / department / due_date) com relatório parcial.
 */
final class OperationalProcessBulkService
{
    public const MAX_ITEMS = 100;

    public const ACTIONS = [
        'archive',
        'assign',
        'set_department',
        'set_due_date',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly MembershipResolver $memberships,
        private readonly OperationalProcessService $processes,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  list<array{id: int, lock_version: int}>  $items
     * @param  array{
     *   action?: string|null,
     *   assignee_membership_id?: int|null,
     *   work_department_id?: int|null,
     *   due_date?: string|null
     * }  $changes
     * @return array{succeeded: list<OperationalProcess>, failed: list<array{id: int, message: string}>}
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

        $processes = OperationalProcess::query()
            ->where('office_id', $officeId)
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        if ($action === 'assign') {
            if (empty($changes['assignee_membership_id'])) {
                throw ValidationException::withMessages([
                    'changes.assignee_membership_id' => ['Responsável obrigatório para atribuir.'],
                ]);
            }
            $this->memberships->requireActiveMembership((int) $changes['assignee_membership_id']);
        }
        if ($action === 'set_department') {
            if (empty($changes['work_department_id'])) {
                throw ValidationException::withMessages([
                    'changes.work_department_id' => ['Departamento obrigatório.'],
                ]);
            }
            $this->memberships->requireActiveDepartment((int) $changes['work_department_id']);
        }
        if ($action === 'set_due_date') {
            if (empty($changes['due_date'])) {
                throw ValidationException::withMessages([
                    'changes.due_date' => ['Prazo obrigatório.'],
                ]);
            }
        }

        $correlation = $this->audit->correlationId();
        $succeeded = [];
        $failed = [];

        foreach ($ids as $id) {
            $process = $processes->get($id);
            if ($process === null) {
                $failed[] = ['id' => $id, 'message' => 'Processo inválido ou de outro escritório.'];

                continue;
            }

            try {
                $this->applyOne($actor, $process, $versionMap[$id], $action, $changes);
                $updated = $process->fresh(['tasks']) ?? $process;
                $this->audit->record('work.process.bulk', 'SUCCESS', $updated, [
                    'correlation' => $correlation,
                    'action' => $action,
                ]);
                $succeeded[] = $updated;
            } catch (AuthorizationException) {
                $failed[] = ['id' => $id, 'message' => 'Sem permissão para este processo.'];
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
    private function applyOne(User $actor, OperationalProcess $process, int $lockVersion, string $action, array $changes): void
    {
        $gate = Gate::forUser($actor);

        if ($action === 'archive') {
            if (! $gate->allows('archive', $process)) {
                throw new AuthorizationException;
            }
            OptimisticLock::assert($process, $lockVersion, 'operational_process');
            $this->processes->archive($process, $lockVersion);

            return;
        }

        if (! $gate->allows('update', $process)) {
            throw new AuthorizationException;
        }

        $payload = match ($action) {
            'assign' => ['assignee_membership_id' => (int) $changes['assignee_membership_id']],
            'set_department' => ['work_department_id' => (int) $changes['work_department_id']],
            'set_due_date' => ['due_date' => (string) $changes['due_date']],
            default => throw ValidationException::withMessages(['changes.action' => ['Ação de lote inválida.']]),
        };

        $this->processes->update($process, $lockVersion, $payload);
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function resolveAction(array $changes): string
    {
        $action = isset($changes['action']) ? (string) $changes['action'] : '';
        if ($action === '' || ! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['changes.action' => ['Ação de lote inválida.']]);
        }

        return $action;
    }
}
