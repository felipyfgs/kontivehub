<?php

namespace App\Services\Serpro;

use App\Contracts\IntegraContadorClient;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\DTO\Serpro\MutationAuthorization;
use App\DTO\Serpro\SerproOperationCommand;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalProfile;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproEnvironment;
use App\Enums\SerproFunctionalRoute;
use App\Enums\SerproUsageResult;
use App\Models\Client;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproOperationAttempt;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Fiscal\Availability\FiscalOperationClassifier;
use App\Services\Fiscal\Guides\PagtowebEphemeralResponseRedactor;
use App\Services\Integra\AutenticaProcuradorEtag;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Integra\FixtureIntegraContadorClient;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\SerproTechnicalParameterGuard;
use App\Services\Platform\OfficeSubscriptionGate;
use App\Services\Serpro\Catalog\OperationCoordinateResolver;
use App\Services\Serpro\Catalog\OperationCoverageMatrix;
use App\Services\Serpro\Usage\UsageLedgerService;
use App\Services\Serpro\Usage\UsageReserveRequest;
use Illuminate\Support\Str;
use Throwable;

/**
 * Executor único das operações produtivas do Integra Contador.
 *
 * Consumidores informam somente operation_key e dados de negócio. Coordenadas,
 * rota, versão, auth e procuração são resolvidas pelo catálogo oficial. Mutações
 * são bloqueadas por autorização tipada nesta change.
 *
 * Gates pré-HTTP (fail-closed): contrato, driver/proveniência, flags/allowlist,
 * subscription, kill switches, Termo/token/poder (via eligibility), catálogo/codec,
 * budget, limiter e breaker.
 */
final class SerproOperationService implements SerproOperationExecutor
{
    /** Limite da chave única no ledger técnico de uso SERPRO. */
    private const LEDGER_IDEMPOTENCY_MAX_LENGTH = 120;

    public function __construct(
        private readonly OperationCoordinateResolver $coordinates,
        private readonly SerproContractService $contracts,
        private readonly UsageLedgerService $ledger,
        private readonly ContributorCnpjResolver $contributors,
        private readonly SerproProductionEgressGate $egressGate,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproCircuitBreaker $breaker,
        private readonly SerproRateLimiter $rateLimiter,
        private readonly CapabilityDriverResolver $drivers,
        private readonly OfficeSubscriptionGate $subscriptionGate,
        private readonly IntegraEligibilityService $eligibility,
        private readonly SerproOperationAttemptStore $attempts,
        private readonly SerproRequestTagGenerator $requestTags,
        private readonly OperationCoverageMatrix $coverage,
        private readonly SerproTechnicalParameterGuard $technicalParams,
        private readonly ClientProcuracaoSyncService $procuracaoGate,
        private readonly PreAckDocumentCaptureDispatcher $preAckDocuments,
        private readonly PagtowebEphemeralResponseRedactor $pagtowebResponseRedactor,
        private readonly FiscalModuleAvailabilityService $moduleAvailability,
        private readonly FixtureIntegraContadorClient $fixtureClient,
    ) {}

    /**
     * Resolve o transporte na hora da chamada para respeitar rebinds de teste
     * e impedir client stale no singleton do executor.
     */
    private function client(): IntegraContadorClient
    {
        return app(IntegraContadorClient::class);
    }

    public function run(SerproOperationCommand $command): IntegraResponse
    {
        $office = $command->office;
        $client = $command->client;
        $operationKey = trim($command->operationKey);
        $correlationId = $command->correlationId ?? (string) Str::uuid();
        $entityKey = $command->resolvedEntityKey();
        $mutationAuth = $command->mutationAuthOrNone();

        if ($operationKey === '') {
            return $this->blocked($operationKey, 'OPERATION_KEY_REQUIRED', 'operation_key é obrigatório.', $correlationId);
        }

        if ($client !== null && (int) $client->office_id !== (int) $office->id) {
            return $this->blocked($operationKey, 'CONTRIBUTOR_CROSS_TENANT', 'Cliente não pertence ao escritório ativo.', $correlationId);
        }

        // 1. Catálogo / coordenadas (fail-closed em produção se fonte falhar)
        try {
            $coords = $this->coordinates->resolveExecutable($operationKey);
        } catch (Throwable $e) {
            $code = str_contains($e->getMessage(), 'CATALOG_SOURCE_UNAVAILABLE')
                ? 'CATALOG_SOURCE_UNAVAILABLE'
                : (str_contains($e->getMessage(), 'CAPABILITY_NOT_IMPLEMENTED')
                    ? 'CAPABILITY_NOT_IMPLEMENTED'
                    : 'CAPABILITY_NOT_EXECUTABLE');

            return $this->blocked($operationKey, $code, $e->getMessage(), $correlationId);
        }

        // 2. Coverage: IMPLEMENTED só com matriz completa (não promove inventariado)
        $coverage = $this->coverage->evaluate($operationKey);
        if (! $coverage['eligible_implemented']
            && ! in_array($coords['platform_support']->value ?? '', ['SIMULATED', 'IMPLEMENTED', 'PRODUCTION_VALIDATED'], true)
        ) {
            // resolveExecutable already checks isExecutable; keep as defense-in-depth for matrix
        }

        // 3. Mutação tipada — Emitir/Declarar/mutantes bloqueados nesta change
        $isMutating = (bool) $coords['is_mutating'];
        if ($isMutating && ! $mutationAuth->allowsMutatingOperation($operationKey, true)) {
            return $this->blocked(
                $operationKey,
                'MUTATION_DISABLED',
                'Operação mutante bloqueada por autorização tipada nesta change.',
                $correlationId,
                423,
            );
        }

        $environment = $this->resolveEnvironment($command->environment);
        $route = $coords['route'] ?? null;
        $idSistema = (string) ($coords['id_sistema'] ?? '');

        // 4. Kill switches
        if ($this->killSwitch->isGlobalActive() || (bool) config('fiscal.kill_switch', false)) {
            return $this->blocked($operationKey, 'KILL_SWITCH', 'Kill switch SERPRO ativo.', $correlationId, 503);
        }
        if ($idSistema !== '' && $this->killSwitch->isSolutionBlocked($idSistema)) {
            return $this->blocked($operationKey, 'KILL_SWITCH', 'Solução bloqueada pelo kill switch.', $correlationId, 503);
        }

        // 5. Driver / proveniência (antes de flags: simulated/disabled definem estrito)
        try {
            $driver = $this->drivers->forOperationKey($operationKey);
        } catch (Throwable $e) {
            return $this->blocked($operationKey, 'DRIVER_INVALID', $e->getMessage(), $correlationId, 503);
        }
        if ($driver->value === 'disabled') {
            return $this->blocked($operationKey, 'CAPABILITY_DISABLED', 'Capacidade SERPRO desabilitada.', $correlationId, 503);
        }

        // 6. Disponibilidade provider-neutral — todos disponíveis, restritos por exceção.
        $module = $command->module ?? $this->moduleForOperation($operationKey, $coords);
        // "authorization" é uma classificação técnica de onboarding, não um módulo fiscal.
        if ($module === 'authorization') {
            $module = null;
        }
        if ($module !== null) {
            try {
                $decision = $this->moduleAvailability->resolve(
                    FiscalControlModule::fromRuntimeKey($module),
                    $office,
                    FiscalOperationClassifier::forSerpro($operationKey, $coords),
                );
                if (! $decision->allowed) {
                    return $this->blocked(
                        $operationKey,
                        $decision->reasonCode ?? 'MODULE_UNAVAILABLE',
                        $decision->reason ?? 'Operação fiscal indisponível.',
                        $correlationId,
                        423,
                    );
                }
            } catch (\InvalidArgumentException) {
                return $this->blocked(
                    $operationKey,
                    'MODULE_UNKNOWN',
                    "Módulo fiscal desconhecido: {$module}.",
                    $correlationId,
                    422,
                );
            }
        }

        if ($driver->value === 'fixture') {
            return $this->fixtureClient->execute(new IntegraRequest(
                officeId: (int) $office->id,
                clientId: (int) ($client?->id ?? 0),
                environment: 'DEV',
                contractorCnpj: '11222333000181',
                authorIdentity: '11222333000181',
                contributorCnpj: '11222333000181',
                operationKey: $operationKey,
                businessData: $command->businessData,
                idempotencyKey: $command->idempotencyKey,
                correlationId: $correlationId,
                requestTag: null,
                isMutating: $isMutating,
            ));
        }

        // 7. Subscription — egress real fail-closed (simulado/tests sem assinatura não bloqueiam)
        if ($driver->value === 'real' && ! $this->subscriptionGate->allowsExternalCalls($office)) {
            return $this->blocked($operationKey, 'SUBSCRIPTION_BLOCKED', 'Assinatura do escritório não permite chamadas externas.', $correlationId, 423);
        }

        // 8. Contrato (sempre)
        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            return $this->blocked($operationKey, 'CONTRACT_UNAVAILABLE', 'Contrato SERPRO indisponível.', $correlationId, 503);
        }

        // 8b. Egress faturável — somente driver real (simulado/fake não precisa cutover de credencial)
        $isBillableRoute = $route instanceof SerproFunctionalRoute && ! $route->isNonBillableByRoute();
        if ($driver->value === 'real' && $isBillableRoute) {
            $billableGate = $this->egressGate->evaluateBillableEgress(
                route: $route instanceof SerproFunctionalRoute ? $route : null,
                office: $office,
                environment: $environment,
            );
            if (! $billableGate['allowed']) {
                return $this->blocked(
                    $operationKey,
                    $billableGate['code'] ?? 'EGRESS_BLOCKED',
                    $billableGate['message'] ?? 'Egress faturável bloqueado pelo gate de produção.',
                    $correlationId,
                    423,
                );
            }
        }

        // 9. Circuit breaker
        if (! $this->breaker->isCallAllowed($idSistema !== '' ? $idSistema : null)) {
            return $this->blocked($operationKey, 'CIRCUIT_OPEN', 'Circuit breaker aberto para a solução.', $correlationId, 503);
        }

        // 10. Recusar parâmetros técnicos tenant-facing (autor/termo/OAuth/token/ETag…).
        // If-None-Match é a única exceção interna e somente para o handshake
        // Autentica Procurador; nunca é aceito em outra operation_key.
        $requestHeaders = $command->headers;
        try {
            $this->technicalParams->assertClean($command->businessData, 'businessData');
            $this->technicalParams->assertClean($command->payload, 'payload');
            if ($operationKey === 'autentica_procurador.envio_xml_assinado') {
                $requestHeaders = $this->validatedAutenticaHeaders($requestHeaders);
            } else {
                $this->technicalParams->assertClean($requestHeaders, 'headers');
            }
        } catch (Throwable $e) {
            return $this->blocked(
                $operationKey,
                'TECHNICAL_PARAM_REJECTED',
                $e->getMessage(),
                $correlationId,
                422,
            );
        }

        // 11. Autor / contribuinte — derivados do Office (CurrentOffice no HTTP layer)
        $contractOnly = (string) ($coords['auth_mode'] ?? '') === 'CONTRACT_ONLY';
        $authorization = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        if ($contractOnly) {
            $author = (string) $contract->contractor_cnpj;
        } else {
            // Nunca fallback silencioso para CNPJ do contratante.
            if ($authorization === null) {
                return $this->blocked(
                    $operationKey,
                    'AUTHORIZATION_MISSING',
                    'Autorização SERPRO do escritório ausente.',
                    $correlationId,
                );
            }
            $author = strtoupper(trim((string) ($authorization->author_identity ?? '')));

            // Override interno só se coincidir com o autor do office (ignora tentativa tenant-facing).
            if ($command->authorIdentityOverride !== null && $command->authorIdentityOverride !== '') {
                $override = strtoupper(trim($command->authorIdentityOverride));
                if ($override !== $author && $override !== '' && $override !== '00000000000000') {
                    return $this->blocked(
                        $operationKey,
                        'TECHNICAL_PARAM_REJECTED',
                        'Autor do pedido deve ser derivado da autorização do escritório.',
                        $correlationId,
                        422,
                    );
                }
            }
        }

        if ($author === '' || $author === '00000000000000') {
            return $this->blocked($operationKey, 'AUTHOR_IDENTITY_MISSING', 'Autor do Pedido não configurado.', $correlationId);
        }

        if ($client !== null) {
            try {
                // Contributor vem do cadastro do cliente; override só se idêntico (legado interno).
                $resolvedContributor = $this->contributors->resolve($client);
                if (
                    $command->contributorIdentityOverride !== null
                    && $command->contributorIdentityOverride !== ''
                    && strtoupper(trim($command->contributorIdentityOverride)) !== strtoupper(trim($resolvedContributor))
                ) {
                    return $this->blocked(
                        $operationKey,
                        'TECHNICAL_PARAM_REJECTED',
                        'Contribuinte deve ser derivado do cliente do escritório.',
                        $correlationId,
                        422,
                    );
                }
                $contributor = $resolvedContributor;
            } catch (Throwable) {
                return $this->blocked(
                    $operationKey,
                    'CONTRIBUTOR_IDENTITY_MISSING',
                    'CNPJ completo do contribuinte não encontrado.',
                    $correlationId,
                );
            }
        } else {
            $contributor = $command->contributorIdentityOverride ?? $author;
        }

        // 12. Produção exige procuração. O gateway Trial oficial dispensa
        // jwt_token e autenticar_procurador_token e trabalha com cenários demo.
        if ($environment === SerproEnvironment::Production && $client !== null && ! $contractOnly) {
            $proxyRule = (string) ($coords['proxy_rule'] ?? 'NOT_APPLICABLE');
            /** @var list<string> $requiredPowers */
            $requiredPowers = $coords['required_proxy_powers'] ?? [];
            if ($requiredPowers === [] && ! empty($coords['required_proxy_power'])) {
                $requiredPowers = preg_split('/[\s,]+/', (string) $coords['required_proxy_power']) ?: [];
            }
            if ($proxyRule === 'REQUIRED_WHEN_REPRESENTING' && $author === $contributor) {
                // autor = contribuinte: poder não se aplica
            } else {
                $gate = $this->procuracaoGate->gateForOperation(
                    $office,
                    $client,
                    $environment,
                    array_values(array_filter(array_map('strval', $requiredPowers))),
                    $proxyRule,
                );
                if (! $gate['allowed']) {
                    return $this->blocked(
                        $operationKey,
                        $gate['code'] ?? 'PROXY_POWER_MISSING',
                        $gate['message'] ?? 'Procuração insuficiente para a operação.',
                        $correlationId,
                        422,
                    );
                }
            }
        }

        // 13. Termo / token / poder (eligibility) — somente produção.
        if (
            $environment === SerproEnvironment::Production
            && $client !== null
            && ! $contractOnly
            && $driver->value === 'real'
        ) {
            $elig = $this->eligibility->evaluate(
                $office,
                $client,
                $idSistema,
                (string) ($coords['id_servico'] ?? ''),
                (string) ($coords['id_servico'] ?? ''),
                $environment,
                null,
                $module,
            );
            if (! $elig->eligible) {
                $code = $elig->primaryCode()->value;

                return $this->blocked(
                    $operationKey,
                    $code,
                    'Elegibilidade Integra negada: '.$code,
                    $correlationId,
                    422,
                );
            }
        }

        // 12. Rate limiter local (egress real: fail-closed se limites zero/ausentes)
        try {
            $this->rateLimiter->attempt(
                (int) $office->id,
                $operationKey,
                productiveEgress: $driver->value === 'real',
            );
        } catch (Throwable $e) {
            $code = str_contains($e->getMessage(), 'RATE_LIMIT_NOT_CONFIGURED')
                ? 'RATE_LIMIT_NOT_CONFIGURED'
                : 'RATE_LIMIT_LOCAL';

            return $this->blocked($operationKey, $code, $e->getMessage(), $correlationId, 429);
        }

        // 13. Idempotency namespaced: Office/env/op/entity + key lógica
        $logicalKey = $command->idempotencyKey ?? hash('sha256', implode('|', [
            (string) $office->id,
            (string) ($client?->id ?? 0),
            $operationKey,
            json_encode($command->businessData, JSON_THROW_ON_ERROR),
        ]));
        $idempotencyKey = $this->namespaceIdempotencyKey(
            $environment->value,
            (int) $office->id,
            $operationKey,
            $entityKey,
            $logicalKey,
        );

        // 14. Request tag opaca (≠ idempotency; ≤32; sem PII)
        $requestTag = $this->requestTags->generate([
            'office' => (string) $office->id,
            'env' => $environment->value,
            'op' => $operationKey,
            'entity' => $entityKey,
            'idem' => hash('sha256', $idempotencyKey),
            'corr' => $correlationId,
        ]);
        $this->requestTags->assertOpaque($requestTag);

        // 15. Attempt store — replay/wait/dispatch
        $begin = $this->attempts->beginOrReplay(
            officeId: (int) $office->id,
            environment: $environment->value,
            operationKey: $operationKey,
            entityKey: $entityKey,
            idempotencyKey: $idempotencyKey,
            requestTag: $requestTag,
            correlationId: $correlationId,
            clientId: $client !== null ? (int) $client->id : null,
        );

        if ($begin['action'] === 'replay' && $begin['response'] instanceof IntegraResponse) {
            return $begin['response'];
        }
        if ($begin['action'] === 'wait' && $begin['response'] instanceof IntegraResponse) {
            return $begin['response'];
        }

        $attempt = $begin['attempt'];

        $request = new IntegraRequest(
            officeId: (int) $office->id,
            clientId: $client !== null ? (int) $client->id : 0,
            environment: $environment->value,
            contractorCnpj: (string) $contract->contractor_cnpj,
            authorIdentity: $author,
            contributorCnpj: $contributor,
            operationKey: $operationKey,
            businessData: $command->businessData,
            payload: $command->payload,
            headers: $requestHeaders,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlationId,
            requestTag: $requestTag,
            isMutating: $isMutating,
            // Coordenadas oficiais preservadas no DTO para o transporte e testes de contrato.
            solutionCode: $idSistema !== '' ? $idSistema : null,
            serviceCode: isset($coords['id_servico']) ? (string) $coords['id_servico'] : null,
            operationCode: isset($coords['id_servico'])
                ? (string) ($coords['operation_code'] ?? $coords['id_servico'] ?? '')
                : null,
            eventosBatchContributor: $command->eventosBatchContributor,
        );

        $reservation = $this->ledger->reserve(new UsageReserveRequest(
            officeId: (int) $office->id,
            idempotencyKey: $idempotencyKey,
            systemCode: $idSistema,
            serviceCode: (string) ($coords['id_servico'] ?? ''),
            operationCode: (string) ($coords['operation_key'] ?? $operationKey),
            clientId: $client !== null ? (int) $client->id : null,
            contributorRef: substr(hash('sha256', $contributor), 0, 16),
            correlationId: $correlationId,
            operationKey: $operationKey,
            isSimulated: false,
            functionalRoute: $route instanceof SerproFunctionalRoute
                ? $route->value
                : (string) $route,
            requestTag: $requestTag,
        ));

        if (! $reservation->allowed) {
            if ($attempt !== null) {
                $blocked = $this->blocked($operationKey, 'BUDGET_EXCEEDED', 'Orçamento SERPRO bloqueou a operação.', $correlationId, 429, $requestTag);
                $this->finalizeAttempt($attempt, $blocked);

                return $blocked;
            }

            return $this->blocked($operationKey, 'BUDGET_EXCEEDED', 'Orçamento SERPRO bloqueou a operação.', $correlationId, 429, $requestTag);
        }

        // Replay de reserva finalizada com attempt ausente (legado): não re-HTTP
        if ($reservation->replayed && $reservation->reservation->status->isTerminal()) {
            if ($attempt !== null && $attempt->isTerminal()) {
                return $this->attempts->toResponse($attempt);
            }
        }

        if ($attempt !== null) {
            $this->attempts->attachReservation($attempt, (int) $reservation->reservation->id);
        }

        try {
            $response = $this->client()->execute($request);
        } catch (Throwable) {
            $this->ledger->finalize($reservation->reservation, SerproUsageResult::TransportError, possiblyBillable: true);
            $uncertain = $this->blocked(
                $operationKey,
                'TRANSPORT_ERROR',
                'Falha de transporte Integra Contador (resultado incerto).',
                $correlationId,
                503,
                $requestTag,
            );
            if ($attempt !== null) {
                $this->attempts->markUncertain($attempt, $uncertain);
            }
            $this->breaker->recordFailure($idSistema !== '' ? $idSistema : null, 'transport_error');

            return $uncertain;
        }

        // Nenhuma resposta marcada como sintética pode atravessar a fronteira
        // operacional, mesmo se vier de dado legado ou de um double instalado
        // incorretamente. Trial oficial não possui essa marca e continua distinto.
        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        $this->ledger->finalize(
            $reservation->reservation,
            $this->ledger->mapIntegraResponse($response),
            latencyMs: $response->latencyMs,
            httpStatus: $response->httpStatus > 0 ? $response->httpStatus : null,
        );

        // Correlacionar request tag na resposta se o client não preencheu
        if ($response->requestTag === null || $response->requestTag === '') {
            $response = new IntegraResponse(
                success: $response->success,
                httpStatus: $response->httpStatus,
                body: $response->body,
                headers: $response->headers,
                errorCode: $response->errorCode,
                errorMessage: $response->errorMessage,
                simulated: $response->simulated,
                retryAfterSeconds: $response->retryAfterSeconds,
                correlationId: $response->correlationId ?? $correlationId,
                latencyMs: $response->latencyMs,
                etag: $response->etag,
                expiresHeader: $response->expiresHeader,
                businessStatus: $response->businessStatus,
                mensagens: $response->mensagens,
                dados: $response->dados,
                operationKey: $response->operationKey ?? $operationKey,
                requestTag: $requestTag,
                functionalRoute: $response->functionalRoute
                    ?? ($route instanceof SerproFunctionalRoute ? $route->value : (string) $route),
                sourceProvenance: $response->sourceProvenance,
            );
        }

        if ($operationKey === 'pagtoweb.comparrecadacao') {
            $response = $this->pagtowebResponseRedactor->redact(
                $response,
                $command->businessData['numeroDocumento'] ?? null,
            );
        }

        // Respostas documentais produtivas precisam chegar ao cofre antes do
        // ACK terminal. Assim um crash/replay nunca depende do Base64, que não
        // é persistido no attempt store.
        if ($client !== null
            && $driver->value === 'real'
            && $response->success
            && ! $response->simulated
            && in_array($response->sourceProvenance, [
                FiscalSourceProvenance::SerproTrial->value,
                FiscalSourceProvenance::SerproReal->value,
            ], true)
            && $this->preAckDocuments->handles($operationKey)
        ) {
            try {
                $response = $this->preAckDocuments->capture(
                    operationKey: $operationKey,
                    entityKey: $entityKey,
                    response: $response,
                    officeId: (int) $office->id,
                    clientId: (int) $client->id,
                );
            } catch (Throwable) {
                $captureFailure = $this->blocked(
                    $operationKey,
                    'DOCUMENT_SECURE_CAPTURE_FAILED',
                    'Documento recebido, mas a captura segura pré-ACK falhou.',
                    $correlationId,
                    503,
                    $requestTag,
                );
                if ($attempt !== null) {
                    $this->attempts->markUncertain($attempt, $captureFailure);
                }

                return $captureFailure;
            }
        }

        $uncertainHttp = $response->httpStatus === 0
            || $response->errorCode === 'MUTATION_TIMEOUT_PENDING'
            || $response->errorCode === 'TRANSPORT_ERROR';

        if ($attempt !== null) {
            if ($uncertainHttp) {
                $this->attempts->markUncertain($attempt, $response);
            } else {
                $this->finalizeAttempt($attempt, $response);
            }
        }

        if ($response->success) {
            $this->breaker->recordSuccess($idSistema !== '' ? $idSistema : null);
        } elseif ($this->breaker->isTechnicalFailure($response->httpStatus, $response->errorCode)) {
            $this->breaker->recordFailure(
                $idSistema !== '' ? $idSistema : null,
                $response->errorCode ?? ('http_'.$response->httpStatus),
            );
        }

        return $response;
    }

    /**
     * ACK apenas resultados definitivos; falhas locais recuperáveis abandonam o attempt.
     */
    private function finalizeAttempt(SerproOperationAttempt $attempt, IntegraResponse $response): void
    {
        if (
            ! $response->success
            && SerproAttemptReplayPolicy::isNonStickyError($response->errorCode)
        ) {
            $this->attempts->abandonLocalPrecondition($attempt);

            return;
        }

        $this->attempts->acknowledge($attempt, $response);
    }

    /**
     * @param  array<string, mixed>  $businessData
     */
    public function execute(
        Office $office,
        Client $client,
        string $operationKey,
        array $businessData = [],
        ?string $idempotencyKey = null,
        ?string $correlationId = null,
        ?MutationAuthorization $mutationAuth = null,
        ?string $entityKey = null,
        ?string $module = null,
    ): IntegraResponse {
        return $this->run(new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: $operationKey,
            businessData: $businessData,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlationId,
            entityKey: $entityKey,
            mutationAuth: $mutationAuth,
            module: $module,
        ));
    }

    /**
     * Executa a partir de um IntegraRequest já montado (migração de adapters).
     * Preferir run()/execute() com command; este método evita rebuild de identidades.
     */
    public function executeRequest(IntegraRequest $request, ?MutationAuthorization $mutationAuth = null, ?string $module = null): IntegraResponse
    {
        $office = Office::query()->withoutGlobalScopes()->find($request->officeId);
        if ($office === null) {
            return $this->blocked(
                $request->operationKey,
                'OFFICE_NOT_FOUND',
                'Office não encontrado para a operação.',
                $request->correlationId,
            );
        }

        $client = null;
        if ($request->clientId > 0) {
            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereKey($request->clientId)
                ->first();
            if ($client === null) {
                return $this->blocked(
                    $request->operationKey,
                    'CONTRIBUTOR_CROSS_TENANT',
                    'Cliente não pertence ao escritório ativo.',
                    $request->correlationId,
                );
            }
        }

        return $this->run(new SerproOperationCommand(
            office: $office,
            client: $client,
            operationKey: $request->operationKey,
            businessData: $request->businessData !== [] ? $request->businessData : $request->payload,
            idempotencyKey: $request->idempotencyKey,
            correlationId: $request->correlationId,
            mutationAuth: $mutationAuth ?? (
                $request->isMutating ? MutationAuthorization::none() : MutationAuthorization::none()
            ),
            environment: $request->environment,
            payload: $request->payload,
            headers: $request->headers,
            module: $module,
            contributorIdentityOverride: $request->contributorCnpj,
            authorIdentityOverride: $request->authorIdentity,
        ));
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function validatedAutenticaHeaders(array $headers): array
    {
        $validated = [];
        foreach ($headers as $name => $value) {
            if (strcasecmp((string) $name, 'If-None-Match') !== 0) {
                $this->technicalParams->assertClean([(string) $name => $value], 'headers');
                $validated[(string) $name] = $value;

                continue;
            }

            $validated['If-None-Match'] = AutenticaProcuradorEtag::assertValidCondition($value);
        }

        return $validated;
    }

    private function namespaceIdempotencyKey(
        string $environment,
        int $officeId,
        string $operationKey,
        string $entityKey,
        string $logicalKey,
    ): string {
        // Namespaced, estável, sem PII em claro; distinto de request_tag.
        $raw = implode('|', [
            'ic',
            strtoupper($environment),
            (string) $officeId,
            $operationKey,
            $entityKey,
            $logicalKey,
        ]);

        // O attempt aceita 190, mas o ledger técnico usa varchar(120).
        // A mesma chave precisa servir aos dois para preservar o replay idempotente.
        if (strlen($raw) <= self::LEDGER_IDEMPOTENCY_MAX_LENGTH) {
            return $raw;
        }

        return 'ic:'.hash('sha256', $raw);
    }

    private function resolveEnvironment(?string $raw): SerproEnvironment
    {
        return FiscalProfile::configured()->serproEnvironment();
    }

    /**
     * @param  array<string, mixed>  $coords
     */
    private function moduleForOperation(string $operationKey, array $coords): ?string
    {
        $fromMeta = $coords['monitoring_module'] ?? null;
        if (is_string($fromMeta) && $fromMeta !== '' && $fromMeta !== 'authorization') {
            return $this->normalizeFeatureModule($fromMeta);
        }

        $capability = $this->drivers->capabilityForOperationKey($operationKey);

        return $this->normalizeFeatureModule(match ($capability) {
            'sitfis' => 'sitfis',
            'mailbox' => 'mailbox',
            'dctfweb' => 'dctfweb_mit',
            'simples_mei' => 'simples_mei',
            'installments' => 'parcelamentos',
            'guides' => 'guias',
            'registrations' => 'cadastros',
            'tax_processes' => 'processos_fiscais',
            'authorization' => null,
            default => null,
        });
    }

    /**
     * Normaliza aliases do catálogo para as dez chaves provider-neutral.
     */
    private function normalizeFeatureModule(?string $module): ?string
    {
        if ($module === null || $module === '' || $module === 'authorization' || $module === 'inventory') {
            return null;
        }

        return match ($module) {
            'dctfweb', 'dctfweb_mit', 'mit' => 'dctfweb',
            'installments', 'parcelamentos', 'parcelamento' => 'parcelamentos',
            'guides', 'guias', 'sicalc', 'pagtoweb' => 'guias',
            'simples_mei', 'pgdasd', 'pgmei', 'defis', 'regimeapuracao', 'ccmei' => 'simples_mei',
            'sitfis', 'situacao_fiscal' => 'situacao_fiscal',
            'mailbox', 'caixa_postal', 'dte' => 'caixa_postal',
            'declaracoes' => 'declaracoes',
            'fgts' => 'fgts',
            'tax_processes', 'eprocesso' => 'processos_fiscais',
            'registrations', 'pnr_contador' => 'cadastros',
            default => FiscalControlModule::tryFrom($module)?->value,
        };
    }

    private function blocked(
        string $operationKey,
        string $code,
        string $message,
        ?string $correlationId,
        int $status = 422,
        ?string $requestTag = null,
    ): IntegraResponse {
        return new IntegraResponse(
            success: false,
            httpStatus: $status,
            body: [],
            errorCode: $code,
            errorMessage: $message,
            correlationId: $correlationId,
            operationKey: $operationKey !== '' ? $operationKey : null,
            requestTag: $requestTag,
            sourceProvenance: FiscalSourceProvenance::Unverified->value,
        );
    }
}
