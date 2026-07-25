<?php

namespace App\Services\Fiscal\Mutations;

use App\Enums\FiscalMutationStatus;
use App\Models\FiscalMutationOperation;
use App\Models\FiscalMutationOperationEvent;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Transições de estado PENDING/SENT/CONFIRMED/REJECTED/UNKNOWN_RESULT/RECONCILING (13.5).
 */
final class FiscalMutationStateMachine
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Claim atômico PENDING → SENT sob lock.
     * Retorna a operação se este caller ganhou o envio; null se já SENT/terminal/outro estado.
     * Impede double-submit concorrente no transporte.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $attributes
     */
    public function claimSend(
        FiscalMutationOperation $operation,
        string $event = 'send',
        array $context = [],
        array $attributes = [],
        ?int $actorUserId = null,
        string $result = 'SUCCESS',
    ): ?FiscalMutationOperation {
        return DB::transaction(function () use ($operation, $event, $context, $attributes, $actorUserId, $result) {
            /** @var FiscalMutationOperation $locked */
            $locked = FiscalMutationOperation::query()
                ->withoutGlobalScopes()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status !== FiscalMutationStatus::Pending) {
                return null;
            }

            return $this->applyTransition(
                $locked,
                FiscalMutationStatus::Pending,
                FiscalMutationStatus::Sent,
                $event,
                $context,
                $attributes,
                $actorUserId,
                $result,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $context  será redigido
     * @param  array<string, mixed>  $attributes  campos extras no model
     */
    public function transition(
        FiscalMutationOperation $operation,
        FiscalMutationStatus $to,
        string $event,
        array $context = [],
        array $attributes = [],
        ?int $actorUserId = null,
        string $result = 'SUCCESS',
    ): FiscalMutationOperation {
        return DB::transaction(function () use ($operation, $to, $event, $context, $attributes, $actorUserId, $result) {
            /** @var FiscalMutationOperation $locked */
            $locked = FiscalMutationOperation::query()
                ->withoutGlobalScopes()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();

            $from = $locked->status;
            if ($from === $to) {
                return $locked;
            }

            if (! $from->canTransitionTo($to)) {
                throw new LogicException(
                    "Transição inválida de mutação fiscal: {$from->value} → {$to->value} (event={$event})."
                );
            }

            return $this->applyTransition(
                $locked,
                $from,
                $to,
                $event,
                $context,
                $attributes,
                $actorUserId,
                $result,
            );
        });
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $attributes
     */
    private function applyTransition(
        FiscalMutationOperation $locked,
        FiscalMutationStatus $from,
        FiscalMutationStatus $to,
        string $event,
        array $context,
        array $attributes,
        ?int $actorUserId,
        string $result,
    ): FiscalMutationOperation {
        $safe = $this->audit->redact($context);

        $fill = array_merge($attributes, [
            'status' => $to,
        ]);

        if ($to === FiscalMutationStatus::Sent && $locked->sent_at === null) {
            $fill['sent_at'] = now();
        }

        if ($to->isTerminal()) {
            $fill['terminal_at'] = now();
        }

        $locked->forceFill($fill)->save();

        FiscalMutationOperationEvent::query()->create([
            'office_id' => $locked->office_id,
            'fiscal_mutation_operation_id' => $locked->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'event' => $event,
            'result' => $result,
            'correlation_id' => $locked->correlation_id,
            'actor_user_id' => $actorUserId,
            'context' => $safe,
            'created_at' => now(),
        ]);

        $this->audit->record(
            action: 'fiscal.mutation.'.$event,
            result: $result,
            subject: $locked,
            context: [
                'from' => $from->value,
                'to' => $to->value,
                'solution' => $locked->solution_code,
                'service' => $locked->service_code,
                'operation' => $locked->operation_code,
                'client_id' => $locked->client_id,
            ],
            userId: $actorUserId,
            officeId: (int) $locked->office_id,
        );

        return $locked->refresh();
    }
}
