<?php

namespace App\Services\MeiAutomation;

use App\Contracts\SerproFiscalMutationTransport;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalMutationStatus;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use App\Services\Fiscal\Mutations\FiscalMutationIntegraRequestFactory;
use App\Services\Fiscal\Mutations\FiscalMutationStateMachine;
use Illuminate\Support\Facades\DB;
use Throwable;

final class MeiDasMutationReconciler
{
    public function __construct(
        private readonly FiscalMutationStateMachine $stateMachine,
        private readonly FiscalMutationIntegraRequestFactory $requests,
        private readonly SerproFiscalMutationTransport $serpro,
        private readonly MeiProviderPolicy $policy,
        private readonly MeiAutomationAttemptRepository $attempts,
    ) {}

    public function reconcile(MeiAutomationAttempt $attempt): void
    {
        if ($attempt->fiscal_mutation_operation_id === null || $attempt->status->shouldPoll()) {
            return;
        }
        $operation = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $attempt->office_id)
            ->whereKey($attempt->fiscal_mutation_operation_id)
            ->first();
        if ($operation === null || $operation->status->isTerminal()) {
            return;
        }

        if ($attempt->status === MeiAutomationStatus::Succeeded) {
            $this->confirmPortal($operation, $attempt);

            return;
        }

        $submitted = $attempt->submitted_at !== null
            || $attempt->status === MeiAutomationStatus::Uncertain;
        if ($submitted) {
            $this->markUncertain($operation, $attempt);

            return;
        }

        if ($this->mayFallback($operation, $attempt)) {
            if ($this->claimFallback($operation)) {
                $this->attempts->markFallback($attempt, (string) ($attempt->error_code ?: 'PORTAL_UNAVAILABLE'));
                $this->executeFallback($operation->refresh());
            }

            return;
        }

        $this->rejectPortal($operation, $attempt);
    }

    private function confirmPortal(FiscalMutationOperation $operation, MeiAutomationAttempt $attempt): void
    {
        $operation = $this->prepareResolution($operation);
        if (! in_array($operation->status, [FiscalMutationStatus::Sent, FiscalMutationStatus::Reconciling], true)) {
            return;
        }
        $this->stateMachine->transition(
            $operation,
            FiscalMutationStatus::Confirmed,
            'portal_confirmed',
            ['attempt_id' => $attempt->id, 'provider' => $attempt->provider?->value],
            [
                'result_code' => 'CONFIRMED',
                'result_message' => 'DAS emitido pelo Portal Receita.',
                'result_sanitized' => [
                    'provider' => MeiProvider::ReceitaPortal->value,
                    'attempt_id' => (int) $attempt->id,
                    'artifact_count' => count($attempt->vault_artifacts ?? []),
                ],
                'evidence_ref' => 'mei-attempt:'.$attempt->id,
                'external_correlation' => $attempt->external_job_id,
            ],
        );
    }

    private function markUncertain(FiscalMutationOperation $operation, MeiAutomationAttempt $attempt): void
    {
        if ($operation->status === FiscalMutationStatus::UnknownResult) {
            return;
        }
        if (! in_array($operation->status, [FiscalMutationStatus::Sent, FiscalMutationStatus::Reconciling], true)) {
            return;
        }
        $this->stateMachine->transition(
            $operation,
            FiscalMutationStatus::UnknownResult,
            'portal_uncertain',
            ['attempt_id' => $attempt->id, 'submitted' => true],
            [
                'result_code' => 'PORTAL_RESULT_UNCERTAIN',
                'result_message' => 'O portal pode ter emitido o DAS; não reenvie antes da reconciliação.',
                'result_sanitized' => [
                    'provider' => MeiProvider::ReceitaPortal->value,
                    'attempt_id' => (int) $attempt->id,
                    'submitted' => true,
                ],
                'evidence_ref' => 'mei-attempt:'.$attempt->id,
            ],
            result: 'UNKNOWN',
        );
    }

    private function rejectPortal(FiscalMutationOperation $operation, MeiAutomationAttempt $attempt): void
    {
        if (! in_array($operation->status, [FiscalMutationStatus::Sent, FiscalMutationStatus::Reconciling], true)) {
            return;
        }
        $this->stateMachine->transition(
            $operation,
            FiscalMutationStatus::Rejected,
            'portal_rejected',
            ['attempt_id' => $attempt->id, 'error_code' => $attempt->error_code],
            [
                'result_code' => (string) ($attempt->error_code ?: 'PORTAL_FAILED'),
                'result_message' => 'O portal não concluiu a emissão do DAS.',
                'evidence_ref' => 'mei-attempt:'.$attempt->id,
            ],
            result: 'REJECTED',
        );
    }

    private function executeFallback(FiscalMutationOperation $operation): void
    {
        try {
            $response = $this->serpro->execute($this->requests->makeForSerpro($operation));
        } catch (Throwable) {
            $this->stateMachine->transition(
                $operation,
                FiscalMutationStatus::UnknownResult,
                'portal_fallback_transport_error',
                attributes: [
                    'result_code' => 'FALLBACK_TRANSPORT_ERROR',
                    'result_message' => 'Contingência SERPRO sem resultado definitivo; não reenvie.',
                ],
                result: 'UNKNOWN',
            );

            return;
        }

        $this->applyFallbackResponse($operation, $response);
    }

    private function applyFallbackResponse(FiscalMutationOperation $operation, IntegraResponse $response): void
    {
        $status = strtoupper((string) ($response->body['status'] ?? ''));
        $attributes = [
            'result_sanitized' => $response->toSanitizedArray(),
            'external_correlation' => $response->correlationId,
            'latency_ms' => $response->latencyMs,
        ];
        if ($response->hasSimulatedSource() || $response->isStillProcessing()
            || $response->errorCode === 'GATEWAY_TIMEOUT' || $status === 'UNKNOWN') {
            $this->stateMachine->transition(
                $operation,
                FiscalMutationStatus::UnknownResult,
                'portal_fallback_uncertain',
                attributes: [...$attributes, 'result_code' => 'FALLBACK_UNCERTAIN', 'result_message' => 'Contingência sem resultado definitivo.'],
                result: 'UNKNOWN',
            );

            return;
        }
        if ($response->success && in_array($status, ['CONFIRMED', 'OK', 'SUCCESS', ''], true)) {
            $this->stateMachine->transition(
                $operation,
                FiscalMutationStatus::Confirmed,
                'portal_fallback_confirmed',
                ['provider' => MeiProvider::Serpro->value],
                [...$attributes, 'result_code' => 'CONFIRMED', 'result_message' => 'DAS emitido pela contingência SERPRO.'],
            );

            return;
        }
        $this->stateMachine->transition(
            $operation,
            FiscalMutationStatus::Rejected,
            'portal_fallback_rejected',
            ['provider' => MeiProvider::Serpro->value],
            [...$attributes, 'result_code' => $response->errorCode ?? 'REJECTED', 'result_message' => $response->errorMessage ?? 'Contingência rejeitada.'],
            result: 'REJECTED',
        );
    }

    private function prepareResolution(FiscalMutationOperation $operation): FiscalMutationOperation
    {
        if ($operation->status === FiscalMutationStatus::UnknownResult) {
            return $this->stateMachine->transition(
                $operation,
                FiscalMutationStatus::Reconciling,
                'portal_reconcile_start',
            );
        }

        return $operation;
    }

    private function mayFallback(FiscalMutationOperation $operation, MeiAutomationAttempt $attempt): bool
    {
        if (! in_array((string) $attempt->error_code, [
            'PORTAL_UNAVAILABLE',
            'PORTAL_DRIFT',
            'CAPTCHA_EXHAUSTED',
            'PORTAL_CNPJ_FORMAT_UNSUPPORTED',
        ], true)) {
            return false;
        }
        $office = Office::query()->find($operation->office_id);
        if ($office === null) {
            return false;
        }

        return in_array(MeiProvider::Serpro, array_slice(
            $this->policy->providers($office, (string) $attempt->operation_key),
            1,
        ), true);
    }

    private function claimFallback(FiscalMutationOperation $operation): bool
    {
        return DB::transaction(function () use ($operation): bool {
            $locked = FiscalMutationOperation::query()
                ->withoutGlobalScopes()
                ->whereKey($operation->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($locked->status !== FiscalMutationStatus::Sent
                || $locked->result_code !== 'MEI_PORTAL_PROCESSING') {
                return false;
            }
            $locked->forceFill([
                'result_code' => 'PORTAL_FALLBACK_DISPATCHING',
                'result_message' => 'Contingência SERPRO em execução.',
            ])->save();

            return true;
        });
    }
}
