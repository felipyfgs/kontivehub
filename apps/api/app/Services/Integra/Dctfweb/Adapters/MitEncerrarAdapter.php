<?php

namespace App\Services\Integra\Dctfweb\Adapters;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Models\User;
use App\Services\Integra\Dctfweb\DctfwebCodes;
use App\Services\Integra\Dctfweb\DctfwebCompetenceResolver;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;
use App\Services\Integra\Dctfweb\MitApuracaoService;

/**
 * Encerramento MIT — mutante, desabilitado por flag (9.8).
 */
final class MitEncerrarAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly DctfwebMutationGuard $mutations,
        private readonly MitApuracaoService $mit,
    ) {
        parent::__construct($caller, $competences);
    }

    public function systemCode(): string
    {
        return DctfwebCodes::SYSTEM_MIT;
    }

    public function serviceCode(): string
    {
        return DctfwebCodes::SERVICE_MIT;
    }

    public function operationCode(): string
    {
        return DctfwebCodes::OP_MIT_ENCERRAR;
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::Mutating;
    }

    public function coverage(): FiscalCoverage
    {
        return FiscalCoverage::Partial;
    }

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $periodKey = $this->resolvePeriodKey($request);
        $actor = $this->resolveActor($request);

        $gate = $this->mutations->assertMayMutate(
            office: $request->office,
            client: $request->client,
            systemCode: $this->systemCode(),
            serviceCode: $this->serviceCode(),
            operationCode: $this->operationCode(),
            periodKey: $periodKey,
            actor: $actor,
        );

        if (! $gate['allowed']) {
            return FiscalAdapterResult::blocked(
                $gate['message'] ?? 'Mutação bloqueada.',
                $gate['code'] ?? 'MUTATING_DISABLED',
            );
        }

        $attempt = $this->mutations->beginAttempt(
            office: $request->office,
            client: $request->client,
            systemCode: $this->systemCode(),
            serviceCode: $this->serviceCode(),
            operationCode: $this->operationCode(),
            idempotencyKey: $request->run->idempotency_key,
            periodKey: $periodKey,
            correlationId: $request->run->correlation_id,
            competenceId: $request->competence?->id,
        );

        $claimed = $this->mutations->claimForUpstream($attempt);
        if ($claimed === null) {
            return FiscalAdapterResult::blocked(
                'Tentativa de mutação já em voo ou finalizada (claim perdido).',
                'MUTATION_CLAIM_LOST',
            );
        }
        $attempt = $claimed;

        $response = $this->callUpstream($request, [
            'competencia' => $periodKey,
            'periodo' => $periodKey,
            'idempotencyKey' => $request->run->idempotency_key,
        ]);

        if (! $response->success) {
            $code = $response->errorCode ?? 'UPSTREAM_ERROR';
            if (in_array($code, ['TIMEOUT', 'UNCERTAIN', 'UNCERTAIN_TIMEOUT'], true)
                || $response->httpStatus === 504) {
                $this->mutations->markUncertain($attempt, 'UNCERTAIN_TIMEOUT', $response->errorMessage);

                return new FiscalAdapterResult(
                    result: FiscalRunResult::Failed,
                    situation: FiscalSituation::Error,
                    coverage: FiscalCoverage::Partial,
                    errorCode: 'UNCERTAIN_TIMEOUT',
                    errorMessage: 'Encerramento MIT com resultado incerto — retry bloqueado até reconciliação.',
                    skipReason: 'UNCERTAIN_TIMEOUT',
                );
            }

            $this->mutations->markFailed($attempt, $code, $response->errorMessage);

            return $this->failedFromResponse($response);
        }

        $this->mutations->markConfirmed($attempt);

        $bytes = DctfwebIntegraCaller::evidenceBytes($response->body);
        $mit = $this->mit->projectSituacao(
            $request->office,
            $request->client,
            $periodKey,
            array_merge($response->body, ['encerrado' => true, 'status' => 'ENCERRADO']),
        );

        return $this->successResult(
            situation: $mit->situation ?? FiscalSituation::Attention,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'operation' => 'ENCERRAR',
                'encerramento_status' => $mit->encerramento_status?->value,
                'dctfweb_transmission_status' => $mit->dctfweb_transmission_status?->value,
                'mutation_attempt_id' => $attempt->id,
            ],
            coverage: FiscalCoverage::Partial,
        );
    }

    private function resolveActor(FiscalAdapterRequest $request): ?User
    {
        $actorId = $request->run->triggered_by;
        if ($actorId === null || ! is_numeric($actorId)) {
            return null;
        }

        return User::query()->find((int) $actorId);
    }
}
