<?php

namespace App\Services\Fiscal\Mutations;

use App\Contracts\FiscalMutationTransport;
use App\Enums\FiscalMutationDenialCode;
use App\Enums\FiscalMutationStatus;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\FiscalMutationOperationEvent;
use App\Models\Office;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Serpro\Catalog\OperationKeyMap;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orquestra preflight, execução idempotente e reconciliação de mutações fiscais (13.2–13.6).
 */
final class FiscalMutationService
{
    public function __construct(
        private readonly FiscalMutationPolicy $policy,
        private readonly FiscalMutationStateMachine $stateMachine,
        private readonly FiscalMutationTransport $transport,
        private readonly FiscalMutationIntegraRequestFactory $requests,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Preflight: efeito, contribuinte, competência, elegibilidade, custo e confirmação exigida.
     *
     * @param  array<string, mixed>  $requestPayload  será sanitizado
     */
    public function preflight(
        Office $office,
        Client $client,
        User $user,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        ?string $competencePeriodKey = null,
        ?string $idempotencyKey = null,
        ?string $environment = null,
        array $requestPayload = [],
        ?string $module = null,
        ?string $providerOperationKey = null,
        bool $requireRecentAuth = true,
    ): MutationPreflightResult {
        $environment = SerproEnvironment::tryFrom(
            strtoupper($environment ?? (string) config('fiscal_mutations.default_environment', 'TRIAL'))
        ) ?? SerproEnvironment::Trial;

        $solutionCode = strtoupper($solutionCode);
        $serviceCode = strtoupper($serviceCode);
        $operationCode = strtoupper($operationCode);
        $providerOperationKey ??= $this->meiProviderOperationKey(
            $solutionCode,
            $serviceCode,
            $operationCode,
            $requestPayload,
        ) ?? OperationKeyMap::resolve(
            null,
            $solutionCode,
            $serviceCode,
            $operationCode,
        );
        $module = $module ?? FiscalMutationCohort::moduleForSolution($solutionCode);
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);
        $correlationId = $this->audit->correlationId();
        $logicalKey = $this->logicalKey(
            $office->id,
            $client->id,
            $solutionCode,
            $serviceCode,
            $operationCode,
            $competencePeriodKey,
        );

        // Idempotência: reutiliza operação existente com mesma chave
        $existing = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertIdempotentRequestMatches(
                $existing,
                $client,
                $solutionCode,
                $serviceCode,
                $operationCode,
                $requestPayload,
                $providerOperationKey,
            );
            $replayEligible = $existing->status === FiscalMutationStatus::Pending
                && $existing->isPreflightValid()
                && $existing->denial_code === null;

            return new MutationPreflightResult(
                eligible: $replayEligible,
                payload: $this->preflightPayloadFromOperation($existing, replayed: true),
                operation: $existing,
            );
        }

        $policy = $this->policy->evaluate(
            office: $office,
            client: $client,
            user: $user,
            solutionCode: $solutionCode,
            serviceCode: $serviceCode,
            operationCode: $operationCode,
            environment: $environment,
            competencePeriodKey: $competencePeriodKey,
            module: $module,
            options: [
                'require_totp' => $requireRecentAuth,
                'skip_anti_repeat' => false,
                'provider_operation_key' => $providerOperationKey ?? $this->meiProviderOperationKey(
                    $solutionCode,
                    $serviceCode,
                    $operationCode,
                    $requestPayload,
                ),
            ],
        );

        $ttl = max(30, (int) config('fiscal_mutations.preflight_ttl_seconds', 600));
        $effect = $this->buildEffectSummary($solutionCode, $serviceCode, $operationCode, $competencePeriodKey);
        $confirmationPhrase = $this->buildConfirmationPhrase($operationCode);
        $cost = $policy->context['cost_estimate'] ?? null;
        $sanitizedRequest = $this->sanitizeRequest($requestPayload);
        $snapshot = $this->buildPreOperationSnapshot($office, $client, $competencePeriodKey, $policy);

        $operation = FiscalMutationOperation::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'requested_by' => $user->id,
            'idempotency_key' => $idempotencyKey,
            'logical_key' => $logicalKey,
            'correlation_id' => $correlationId,
            'preflight_token' => (string) Str::uuid(),
            'environment' => $environment,
            'solution_code' => $solutionCode,
            'service_code' => $serviceCode,
            'operation_code' => $operationCode,
            'provider_operation_key' => $providerOperationKey,
            'module_key' => $module,
            'competence_period_key' => $competencePeriodKey,
            'status' => FiscalMutationStatus::Pending,
            'effect_summary' => $effect,
            'confirmation_phrase' => $confirmationPhrase,
            'confirmation_required' => true,
            'request_sanitized' => $sanitizedRequest,
            'request_payload_encrypted' => $requestPayload,
            'request_payload_digest' => FiscalMutationPayload::digest($requestPayload),
            'pre_operation_snapshot' => $snapshot,
            'eligibility_snapshot' => $policy->toArray(),
            'cost_estimate' => is_array($cost) ? $cost : null,
            'estimated_cost_micros' => is_array($cost) ? ($cost['estimated_cost_micros'] ?? null) : null,
            'preflight_at' => now(),
            'preflight_expires_at' => now()->addSeconds($ttl),
            'denial_code' => $policy->primaryCode()?->value,
            'denial_message' => $policy->primaryCode()?->message(),
        ]);

        $this->stateMachine->transition(
            operation: $operation,
            to: FiscalMutationStatus::Pending,
            event: 'preflight',
            context: [
                'eligible' => $policy->allowed,
                'codes' => array_map(fn ($c) => $c->value, $policy->codes),
            ],
            actorUserId: $user->id,
            result: $policy->allowed ? 'SUCCESS' : 'BLOCKED',
        );

        // transition no-op se same status — garantir evento de preflight
        if ($operation->events()->count() === 0) {
            FiscalMutationOperationEvent::query()->create([
                'office_id' => $office->id,
                'fiscal_mutation_operation_id' => $operation->id,
                'from_status' => null,
                'to_status' => FiscalMutationStatus::Pending->value,
                'event' => 'preflight',
                'result' => $policy->allowed ? 'SUCCESS' : 'BLOCKED',
                'correlation_id' => $correlationId,
                'actor_user_id' => $user->id,
                'context' => $this->audit->redact([
                    'eligible' => $policy->allowed,
                    'codes' => array_map(fn ($c) => $c->value, $policy->codes),
                ]),
                'created_at' => now(),
            ]);
        }

        $this->audit->record(
            action: 'fiscal.mutation.preflight',
            result: $policy->allowed ? 'SUCCESS' : 'BLOCKED',
            subject: $operation,
            context: [
                'solution' => $solutionCode,
                'service' => $serviceCode,
                'operation' => $operationCode,
                'client_id' => $client->id,
                'eligible' => $policy->allowed,
                'denial' => $policy->primaryCode()?->value,
            ],
            userId: $user->id,
            officeId: $office->id,
        );

        return new MutationPreflightResult(
            eligible: $policy->allowed,
            payload: $this->preflightPayloadFromOperation($operation->refresh(), replayed: false, policy: $policy),
            operation: $operation,
        );
    }

    /**
     * Executa mutação após preflight + confirmação explícita.
     *
     * @param  array<string, mixed>  $requestPayload
     */
    public function execute(
        Office $office,
        Client $client,
        User $user,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        string $confirmationPhrase,
        bool $confirmed,
        ?string $competencePeriodKey = null,
        ?string $idempotencyKey = null,
        ?string $preflightToken = null,
        ?string $environment = null,
        array $requestPayload = [],
        ?string $module = null,
        ?string $providerOperationKey = null,
    ): FiscalMutationOperation {
        $idempotencyKey = $this->normalizeIdempotencyKey($idempotencyKey);

        // Double-click / idempotência: retorna operação existente se já enviada/terminal
        $existing = FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            $this->assertIdempotentRequestMatches(
                $existing,
                $client,
                strtoupper($solutionCode),
                strtoupper($serviceCode),
                strtoupper($operationCode),
                $requestPayload,
                $providerOperationKey,
            );
            if ($existing->status->blocksBlindRetry() && $existing->status !== FiscalMutationStatus::Pending) {
                // Replay seguro — não reenvia
                $this->audit->record('fiscal.mutation.execute_replay', 'SUCCESS', $existing, [
                    'status' => $existing->status->value,
                ], $user->id, $office->id);

                return $existing;
            }

            if ($existing->status->isUncertain()) {
                throw new FiscalMutationException(FiscalMutationDenialCode::RetryBlocked, $existing);
            }

            $operation = $existing;
        } else {
            // Sem preflight prévio: cria via preflight embutido
            $pf = $this->preflight(
                office: $office,
                client: $client,
                user: $user,
                solutionCode: $solutionCode,
                serviceCode: $serviceCode,
                operationCode: $operationCode,
                competencePeriodKey: $competencePeriodKey,
                idempotencyKey: $idempotencyKey,
                environment: $environment,
                requestPayload: $requestPayload,
                module: $module,
                providerOperationKey: $providerOperationKey,
            );
            $operation = $pf->operation;
            if ($operation === null) {
                throw new FiscalMutationException(FiscalMutationDenialCode::NotFound);
            }
        }

        if ($preflightToken !== null && $operation->preflight_token !== $preflightToken) {
            throw new FiscalMutationException(FiscalMutationDenialCode::NotFound, $operation);
        }

        if (! $operation->isPreflightValid()) {
            throw new FiscalMutationException(FiscalMutationDenialCode::PreflightExpired, $operation);
        }

        if ($operation->status !== FiscalMutationStatus::Pending) {
            throw new FiscalMutationException(FiscalMutationDenialCode::RetryBlocked, $operation);
        }

        // Revalidação completa pós-preflight (poder pode ter sido revogado).
        // Anti-repeat / uncertain continuam ativos — só excluem o próprio id (self-match).
        $env = $operation->environment ?? SerproEnvironment::Trial;
        $policy = $this->policy->evaluate(
            office: $office,
            client: $client,
            user: $user,
            solutionCode: $operation->solution_code,
            serviceCode: $operation->service_code,
            operationCode: $operation->operation_code,
            environment: $env,
            competencePeriodKey: $operation->competence_period_key,
            module: $operation->module_key,
            options: [
                'require_totp' => true,
                'require_confirmation' => true,
                'confirmed' => $confirmed,
                'exclude_operation_id' => (int) $operation->id,
                'provider_operation_key' => $operation->provider_operation_key ?? $this->meiProviderOperationKey(
                    (string) $operation->solution_code,
                    (string) $operation->service_code,
                    (string) $operation->operation_code,
                    $operation->request_sanitized ?? [],
                ),
            ],
        );

        if (! $policy->allowed) {
            $operation = $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::Rejected,
                event: 'policy_denied',
                context: $policy->toArray(),
                attributes: [
                    'denial_code' => $policy->primaryCode()?->value,
                    'denial_message' => $policy->primaryCode()?->message(),
                    'eligibility_snapshot' => $policy->toArray(),
                ],
                actorUserId: $user->id,
                result: 'BLOCKED',
            );

            throw new FiscalMutationException(
                $policy->primaryCode() ?? FiscalMutationDenialCode::EligibilityBlocked,
                $operation,
            );
        }

        $expectedPhrase = (string) $operation->confirmation_phrase;
        if ($operation->confirmation_required
            && strcasecmp(trim($confirmationPhrase), $expectedPhrase) !== 0
        ) {
            throw new FiscalMutationException(FiscalMutationDenialCode::ConfirmationRequired, $operation);
        }

        $operation->forceFill([
            'confirmed_by_user' => true,
            'confirmed_at' => now(),
            'eligibility_snapshot' => $policy->toArray(),
            'denial_code' => null,
            'denial_message' => null,
            'request_sanitized' => $this->sanitizeRequest($operation->request_payload_encrypted ?? $requestPayload),
            'attempt_count' => $operation->attempt_count + 1,
        ])->save();

        return $this->dispatchTransport($operation, $user);
    }

    /**
     * Reconcilia resultado incerto (13.6) — consulta específica, sem reenviar mutação.
     */
    public function reconcile(
        Office $office,
        FiscalMutationOperation $operation,
        User $user,
    ): FiscalMutationOperation {
        if ((int) $operation->office_id !== (int) $office->id) {
            throw new FiscalMutationException(FiscalMutationDenialCode::NotFound);
        }

        if (! $operation->status->allowsReconciliation()) {
            throw new FiscalMutationException(FiscalMutationDenialCode::RetryBlocked, $operation);
        }

        if ($operation->status === FiscalMutationStatus::UnknownResult
            || $operation->status === FiscalMutationStatus::Sent
        ) {
            $operation = $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::Reconciling,
                event: 'reconcile_start',
                actorUserId: $user->id,
            );
        }

        $request = $this->requests->make($operation);

        try {
            $response = $this->transport->reconcile($request);
        } catch (Throwable $e) {
            $operation = $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::UnknownResult,
                event: 'reconcile_failed',
                context: ['error' => 'transport_error'],
                attributes: [
                    'reconcile_count' => $operation->reconcile_count + 1,
                    'last_reconcile_at' => now(),
                    'result_code' => 'RECONCILE_TRANSPORT_ERROR',
                    'result_message' => 'Falha de transporte na reconciliação.',
                ],
                actorUserId: $user->id,
                result: 'ERROR',
            );

            return $operation;
        }

        if ($response->hasSimulatedSource()) {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::UnknownResult,
                event: 'reconcile_source_rejected',
                context: ['reason' => 'SIMULATED_SOURCE_REJECTED'],
                attributes: ['result_code' => 'SIMULATED_SOURCE_REJECTED', 'result_message' => 'Resposta sintética descartada.'],
                actorUserId: $user->id,
                result: 'UNKNOWN',
            );
        }

        $bodyStatus = strtoupper((string) ($response->body['status'] ?? ''));
        $attrs = [
            'reconcile_count' => $operation->reconcile_count + 1,
            'last_reconcile_at' => now(),
            'result_sanitized' => $response->toSanitizedArray(),
            'latency_ms' => $response->latencyMs,
            'simulated' => $response->simulated,
            'external_correlation' => $response->correlationId,
        ];

        if ($response->success && in_array($bodyStatus, ['CONFIRMED', 'OK', 'SUCCESS'], true)) {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::Confirmed,
                event: 'reconcile_confirmed',
                context: ['body_status' => $bodyStatus],
                attributes: array_merge($attrs, [
                    'result_code' => 'CONFIRMED',
                    'result_message' => 'Confirmado via reconciliação.',
                    'evidence_ref' => isset($response->body['protocol'])
                        ? mb_substr((string) $response->body['protocol'], 0, 120)
                        : null,
                ]),
                actorUserId: $user->id,
            );
        }

        if ($response->success && in_array($bodyStatus, ['REJECTED', 'DENIED', 'ERROR'], true)) {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::Rejected,
                event: 'reconcile_rejected',
                context: ['body_status' => $bodyStatus],
                attributes: array_merge($attrs, [
                    'result_code' => 'REJECTED',
                    'result_message' => 'Rejeitado via reconciliação.',
                ]),
                actorUserId: $user->id,
            );
        }

        // Ainda incerto
        return $this->stateMachine->transition(
            operation: $operation,
            to: FiscalMutationStatus::UnknownResult,
            event: 'reconcile_still_unknown',
            context: ['body_status' => $bodyStatus, 'http' => $response->httpStatus],
            attributes: array_merge($attrs, [
                'result_code' => 'STILL_UNKNOWN',
                'result_message' => 'Reconciliação sem resultado definitivo.',
            ]),
            actorUserId: $user->id,
            result: 'UNKNOWN',
        );
    }

    public function findForOffice(Office $office, int $id): ?FiscalMutationOperation
    {
        return FiscalMutationOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($id)
            ->first();
    }

    private function dispatchTransport(FiscalMutationOperation $operation, User $user): FiscalMutationOperation
    {
        // Claim atômico PENDING → SENT: só o vencedor executa o transporte.
        $claimed = $this->stateMachine->claimSend(
            operation: $operation,
            event: 'send',
            actorUserId: $user->id,
        );

        if ($claimed === null) {
            // Outro worker/request já claimou ou estado não é mais PENDING — não reenvia.
            $fresh = $operation->fresh() ?? $operation;
            $this->audit->record('fiscal.mutation.execute_claim_miss', 'SUCCESS', $fresh, [
                'status' => $fresh->status->value,
            ], $user->id, (int) $fresh->office_id);

            return $fresh;
        }

        $operation = $claimed;
        $request = $this->requests->make($operation);

        try {
            $response = $this->transport->execute($request);
        } catch (Throwable $e) {
            // Timeout / falha após possível processamento remoto → UNKNOWN_RESULT
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::UnknownResult,
                event: 'transport_timeout',
                context: ['error' => class_basename($e)],
                attributes: [
                    'result_code' => 'TRANSPORT_TIMEOUT',
                    'result_message' => 'Timeout ou falha de transporte após envio — reconcilie antes de repetir.',
                    'denial_code' => null,
                    'denial_message' => null,
                ],
                actorUserId: $user->id,
                result: 'UNKNOWN',
            );
        }

        if ($response->hasSimulatedSource()) {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::UnknownResult,
                event: 'source_rejected',
                context: ['reason' => 'SIMULATED_SOURCE_REJECTED'],
                attributes: ['result_code' => 'SIMULATED_SOURCE_REJECTED', 'result_message' => 'Resposta sintética descartada.'],
                actorUserId: $user->id,
                result: 'UNKNOWN',
            );
        }

        $sanitized = $response->toSanitizedArray();
        $bodyStatus = strtoupper((string) ($response->body['status'] ?? ''));

        if ($response->isStillProcessing()) {
            $operation->forceFill([
                'result_code' => 'MEI_PORTAL_PROCESSING',
                'result_message' => 'Automação do portal em processamento.',
                'result_sanitized' => $sanitized,
                'external_correlation' => $response->correlationId,
                'latency_ms' => $response->latencyMs,
            ])->save();
            $this->audit->record(
                'fiscal.mutation.processing',
                'SUCCESS',
                $operation,
                ['provider' => $response->sourceProvenance, 'http' => $response->httpStatus],
                $user->id,
                (int) $operation->office_id,
            );

            return $operation->refresh();
        }

        if ($response->errorCode === 'GATEWAY_TIMEOUT' || $bodyStatus === 'UNKNOWN') {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::UnknownResult,
                event: 'uncertain_response',
                context: ['http' => $response->httpStatus],
                attributes: [
                    'result_code' => $response->errorCode ?? 'UNKNOWN',
                    'result_message' => $response->errorMessage ?? 'Resultado incerto.',
                    'result_sanitized' => $sanitized,
                    'latency_ms' => $response->latencyMs,
                    'simulated' => $response->simulated,
                    'external_correlation' => $response->correlationId,
                ],
                actorUserId: $user->id,
                result: 'UNKNOWN',
            );
        }

        if ($response->success && in_array($bodyStatus, ['CONFIRMED', 'OK', 'SUCCESS', ''], true)) {
            return $this->stateMachine->transition(
                operation: $operation,
                to: FiscalMutationStatus::Confirmed,
                event: 'confirmed',
                context: ['http' => $response->httpStatus],
                attributes: [
                    'result_code' => 'CONFIRMED',
                    'result_message' => 'Operação confirmada pela fonte.',
                    'result_sanitized' => $sanitized,
                    'evidence_ref' => isset($response->body['protocol'])
                        ? mb_substr((string) $response->body['protocol'], 0, 120)
                        : null,
                    'latency_ms' => $response->latencyMs,
                    'simulated' => $response->simulated,
                    'external_correlation' => $response->correlationId,
                ],
                actorUserId: $user->id,
            );
        }

        return $this->stateMachine->transition(
            operation: $operation,
            to: FiscalMutationStatus::Rejected,
            event: 'rejected',
            context: ['http' => $response->httpStatus],
            attributes: [
                'result_code' => $response->errorCode ?? 'REJECTED',
                'result_message' => $response->errorMessage ?? 'Operação rejeitada.',
                'result_sanitized' => $sanitized,
                'latency_ms' => $response->latencyMs,
                'simulated' => $response->simulated,
                'external_correlation' => $response->correlationId,
            ],
            actorUserId: $user->id,
            result: 'REJECTED',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function preflightPayloadFromOperation(
        FiscalMutationOperation $op,
        bool $replayed,
        ?MutationPolicyResult $policy = null,
    ): array {
        $eligible = $policy?->allowed
            ?? ($op->denial_code === null && $op->status === FiscalMutationStatus::Pending);

        return [
            'eligible' => $eligible && $op->isPreflightValid(),
            'replayed' => $replayed,
            'confirmation_required' => $op->confirmation_required,
            'confirmation_phrase' => $op->confirmation_phrase,
            'effect' => $op->effect_summary,
            'contribuinte' => [
                'client_id' => $op->client_id,
                // sem CNPJ completo
            ],
            'competence' => $op->competence_period_key,
            'operation' => [
                'solution_code' => $op->solution_code,
                'service_code' => $op->service_code,
                'operation_code' => $op->operation_code,
                'module_key' => $op->module_key,
                'environment' => $op->environment?->value,
            ],
            'eligibility' => $op->eligibility_snapshot,
            'cost_estimate' => $op->cost_estimate,
            'estimated_cost_micros' => $op->estimated_cost_micros,
            'pre_operation_snapshot' => $op->pre_operation_snapshot,
            'preflight_token' => $op->preflight_token,
            'preflight_expires_at' => $op->preflight_expires_at?->toIso8601String(),
            'idempotency_key' => $op->idempotency_key,
            'correlation_id' => $op->correlation_id,
            'mutation_operation_id' => $op->id,
            'status' => $op->status->value,
            'denial_code' => $op->denial_code,
            'denial_message' => $op->denial_message,
            'codes' => $policy
                ? array_map(fn ($c) => $c->value, $policy->codes)
                : ($op->denial_code ? [$op->denial_code] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizeRequest(array $payload): array
    {
        // Remove valores longos / possíveis XML / base64
        $clean = [];
        foreach ($payload as $key => $value) {
            $lower = strtolower((string) $key);
            if (in_array($lower, ['xml', 'raw', 'payload', 'body', 'pfx', 'password', 'token', 'secret'], true)) {
                $clean[$key] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $clean[$key] = $this->sanitizeRequest($value);

                continue;
            }
            if (is_string($value) && strlen($value) > 200) {
                $clean[$key] = '[truncated:'.strlen($value).']';

                continue;
            }
            $clean[$key] = $value;
        }

        return $this->audit->redact($clean);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPreOperationSnapshot(
        Office $office,
        Client $client,
        ?string $competence,
        MutationPolicyResult $policy,
    ): array {
        return $this->audit->redact([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'client_display' => $client->display_name ?? $client->legal_name,
            'competence' => $competence,
            'snapshot_at' => now()->toIso8601String(),
            'policy_allowed' => $policy->allowed,
            'proxy_power_id' => $policy->context['proxy_power_id'] ?? null,
            'catalog_id' => $policy->context['catalog_id'] ?? null,
        ]);
    }

    private function buildEffectSummary(
        string $solution,
        string $service,
        string $operation,
        ?string $competence,
    ): string {
        $base = "{$solution}/{$service}/{$operation}";
        if ($competence) {
            $base .= " competência {$competence}";
        }

        return mb_substr("Efeito fiscal mutante: {$base}. Esta ação altera estado na fonte oficial e pode gerar custo.", 0, 500);
    }

    private function buildConfirmationPhrase(string $operationCode): string
    {
        return 'CONFIRMO-'.strtoupper($operationCode);
    }

    private function logicalKey(
        int $officeId,
        int $clientId,
        string $solution,
        string $service,
        string $operation,
        ?string $competence,
    ): string {
        return implode('|', [
            $officeId,
            $clientId,
            $solution,
            $service,
            $operation,
            $competence ?? '-',
        ]);
    }

    private function normalizeIdempotencyKey(?string $key): string
    {
        $key = trim((string) $key);
        if ($key === '') {
            return (string) Str::uuid();
        }

        return mb_substr($key, 0, 160);
    }

    /** @param array<string, mixed> $requestPayload */
    private function assertIdempotentRequestMatches(
        FiscalMutationOperation $operation,
        Client $client,
        string $solutionCode,
        string $serviceCode,
        string $operationCode,
        array $requestPayload,
        ?string $providerOperationKey,
    ): void {
        $sameIdentity = (int) $operation->client_id === (int) $client->id
            && strtoupper((string) $operation->solution_code) === strtoupper($solutionCode)
            && strtoupper((string) $operation->service_code) === strtoupper($serviceCode)
            && strtoupper((string) $operation->operation_code) === strtoupper($operationCode);
        $sameProviderKey = $providerOperationKey === null
            || hash_equals((string) $operation->provider_operation_key, $providerOperationKey);
        $samePayload = $requestPayload === []
            || (is_string($operation->request_payload_digest)
                && hash_equals($operation->request_payload_digest, FiscalMutationPayload::digest($requestPayload)));

        if (! $sameIdentity || ! $sameProviderKey || ! $samePayload) {
            throw new FiscalMutationException(FiscalMutationDenialCode::IdempotencyConflict, $operation);
        }
    }

    /** @param array<string, mixed> $payload */
    private function meiProviderOperationKey(
        string $solution,
        string $service,
        string $operation,
        array $payload,
    ): ?string {
        if (strtoupper($solution) !== 'INTEGRA_MEI'
            || strtoupper($service) !== 'PGMEI'
            || strtoupper($operation) !== 'GERAR_DAS') {
            return null;
        }

        return strtoupper((string) ($payload['output_format'] ?? 'PDF')) === 'BARCODE'
            ? 'pgmei.gerardascodbarra'
            : 'pgmei.gerardaspdf';
    }
}
