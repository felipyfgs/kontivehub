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
use App\Services\Integra\Dctfweb\DctfwebDeclarationService;
use App\Services\Integra\Dctfweb\DctfwebIntegraCaller;
use App\Services\Integra\Dctfweb\DctfwebMutationGuard;

/**
 * Transmissão DCTFWeb — mutante, desabilitada por flag (9.8).
 * Timeout incerto bloqueia retry e exige reconciliação (9.9).
 */
final class DctfwebTransmitirAdapter extends AbstractDctfwebAdapter
{
    public function __construct(
        DctfwebIntegraCaller $caller,
        DctfwebCompetenceResolver $competences,
        private readonly DctfwebMutationGuard $mutations,
        private readonly DctfwebDeclarationService $declarations,
    ) {
        parent::__construct($caller, $competences);
    }

    public function systemCode(): string
    {
        return DctfwebCodes::SYSTEM_DCTFWEB;
    }

    public function serviceCode(): string
    {
        return DctfwebCodes::SERVICE_DCTFWEB;
    }

    public function operationCode(): string
    {
        return DctfwebCodes::OP_TRANSMITIR;
    }

    public function mutability(): FiscalMutability
    {
        return FiscalMutability::Mutating;
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

        // Claim PENDING→SENT sem blocked_retry permanente; só este worker chama upstream.
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
                    coverage: FiscalCoverage::Full,
                    errorCode: 'UNCERTAIN_TIMEOUT',
                    errorMessage: 'Transmissão com resultado incerto — retry bloqueado até reconciliação.',
                    skipReason: 'UNCERTAIN_TIMEOUT',
                );
            }

            // Falha definitiva: Failed permite nova tentativa com nova chave.
            $this->mutations->markFailed($attempt, $code, $response->errorMessage);

            return $this->failedFromResponse($response);
        }

        $this->mutations->markConfirmed($attempt);

        $bytes = DctfwebIntegraCaller::evidenceBytes($response->body);
        $projected = $this->declarations->projectFromRecibo(
            run: $request->run,
            office: $request->office,
            client: $request->client,
            periodKey: $periodKey,
            evidenceBytes: $bytes,
            body: array_merge($response->body, ['transmitida' => true]),
        );

        return $this->successResult(
            situation: $projected['declaration']->situation ?? FiscalSituation::UpToDate,
            evidenceBytes: $bytes,
            normalized: [
                'period_key' => $periodKey,
                'operation' => 'TRANSMITIR_DECLARACAO',
                'transmission_status' => $projected['declaration']->transmission_status?->value,
                'mutation_attempt_id' => $attempt->id,
            ],
            findings: $this->retificationFinding($projected['retification']),
            coverage: FiscalCoverage::Full,
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
