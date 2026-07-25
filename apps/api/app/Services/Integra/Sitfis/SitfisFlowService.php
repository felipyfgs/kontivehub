<?php

namespace App\Services\Integra\Sitfis;

use App\Contracts\EnsuresClientProcuracaoForConsult;
use App\Contracts\IntegraEligibilityEvaluating;
use App\Contracts\ResolvesSerproCapabilityDriver;
use App\Contracts\SerproOperationExecutor;
use App\Contracts\SitfisIdentityResolving;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationState;
use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Orquestra SITFIS: solicitação → protocolo → espera mínima → emissão com polling respeitoso.
 * Correlação do protocolo fica em progress da run (e correlation_id da run).
 * Gates: elegibilidade Integra + ledger de uso (mesmo pipeline de Simples/MEI).
 */
final class SitfisFlowService
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly SitfisIdentityResolving $identities,
        private readonly SitfisReportParser $parser,
        private readonly IntegraEligibilityEvaluating $eligibility,
        private readonly ResolvesSerproCapabilityDriver $drivers,
        private readonly EnsuresClientProcuracaoForConsult $procuracaoEnsure,
    ) {}

    public function execute(FiscalAdapterRequest $request): FiscalAdapterResult
    {
        $driver = $this->drivers->forCapability('sitfis');
        $request->run->forceFill([
            'operation_key' => $request->progress === []
                ? 'sitfis.solicitar_protocolo'
                : 'sitfis.emitir_relatorio',
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            // Conteúdo ainda não verificado — promove a VERIFIED só no persist pós-parse.
            'verification_state' => FiscalVerificationState::Unverified,
        ])->save();

        $cfg = $this->config();
        $state = SitfisProtocolState::fromProgress($request->progress);
        $now = CarbonImmutable::now();

        try {
            $ids = $this->identities->resolve($request->office, $request->client);
        } catch (RuntimeException $e) {
            return FiscalAdapterResult::blocked($e->getMessage(), 'SITFIS_IDENTITY');
        }

        // Cache /Apoiar 304 sem protocolo: espera expires sem nova chamada SERPRO.
        if ($this->isWaitingCacheExpiry($state, $now)) {
            $wait = max(1, $state->secondsUntilEmitAllowed($now));
            $next = $state->with(
                phase: SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY,
                requeueAfterSeconds: $wait,
            );

            return $this->processingResult(
                state: $next,
                message: 'Aguardando expiração do cache SITFIS antes de nova solicitação de protocolo.',
                requeueAfter: $wait,
            );
        }

        // Ensure poder e-CAC antes de qualquer chamada Integra SITFIS (não na espera pura).
        $needsSerproCall = ! $state->hasProtocol() || $state->canAttemptEmit($now);
        if ($needsSerproCall) {
            $ensureBlocked = $this->ensureProxyPower($request, $ids, $cfg);
            if ($ensureBlocked !== null) {
                return $ensureBlocked;
            }
        }

        // Fase 1: solicitar protocolo se ainda não houver (SITFIS 2.0 /Apoiar)
        if (! $state->hasProtocol()) {
            return $this->solicit($request, $ids, $cfg, $now);
        }

        // Fase 2: espera mínima oficial — sem chamada faturável/agressiva
        if (! $state->canAttemptEmit($now)) {
            $wait = $state->secondsUntilEmitAllowed($now);
            $wait = max(1, $wait);
            $next = $state->with(
                phase: SitfisProtocolState::PHASE_WAITING_MIN_PERIOD,
                requeueAfterSeconds: $wait,
            );

            return $this->processingResult(
                state: $next,
                message: 'Aguardando prazo mínimo oficial antes da emissão do relatório SITFIS.',
                requeueAfter: $wait,
            );
        }

        // Fase 3: emitir / consultar resultado
        return $this->emit($request, $ids, $cfg, $state, $now);
    }

    /**
     * @param  array{environment: SerproEnvironment, contract: SerproContract, contractor_cnpj: string, author_identity: string, contributor_cnpj: string}  $ids
     * @param  array<string, mixed>  $cfg
     */
    private function solicit(FiscalAdapterRequest $request, array $ids, array $cfg, CarbonImmutable $now): FiscalAdapterResult
    {
        $correlation = $request->run->correlation_id ?? (string) Str::uuid();
        // Oficial: SOLICITARPROTOCOLO91 em /Apoiar com dados vazio
        $response = $this->call(
            $request,
            $ids,
            (string) ($cfg['solicit_operation_key'] ?? 'sitfis.solicitar_protocolo'),
            (string) ($cfg['solicit_operation'] ?? 'SOLICITARPROTOCOLO91'),
            businessData: [],
            dadosMode: 'EMPTY',
            correlation: $correlation,
        );

        // Doc SERPRO: 304 = cache de protocolo válido no ETag (body vazio). Sem force-retry imediato.
        $protocol = $this->extractProtocolFromResponse($response);

        if (! $response->success) {
            if ($this->isGateBlock($response)) {
                return FiscalAdapterResult::blocked(
                    $response->errorMessage ?? 'Elegibilidade/orçamento SITFIS negado.',
                    $response->errorCode ?? 'SITFIS_GATE',
                );
            }

            return FiscalAdapterResult::failed(
                $response->errorMessage ?? 'Falha ao solicitar relatório SITFIS.',
                $response->errorCode ?? 'SITFIS_SOLICIT_FAILED',
                FiscalCoverage::Full,
            );
        }

        if ($response->hasSimulatedSource()) {
            return FiscalAdapterResult::blocked('Resposta sintética não pode iniciar protocolo SITFIS.', 'SIMULATED_SOURCE_REJECTED');
        }

        if ($protocol === null || $protocol === '') {
            // 304 sem ETag parseável: espera expires (ou fallback) e retoma solicitação.
            if ($this->isEmptyNotModified($response)) {
                $wait = $this->resolveCacheWaitSeconds($response, $cfg);
                $notBefore = $now->addSeconds($wait);
                $state = new SitfisProtocolState(
                    phase: SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY,
                    protocol: null,
                    requestedAt: $now,
                    notBefore: $notBefore,
                    pollCount: 0,
                    lastPollAt: null,
                    correlationId: $correlation,
                    requeueAfterSeconds: $wait,
                    simulated: $response->simulated,
                );

                return $this->processingResult(
                    state: $state,
                    message: 'Cache SITFIS (304) sem protocolo no ETag; aguardando expiração antes de nova solicitação.',
                    requeueAfter: $wait,
                );
            }

            return FiscalAdapterResult::failed(
                'Solicitação SITFIS sem protocolo correlacionável.',
                'SITFIS_PROTOCOL_MISSING',
                FiscalCoverage::Full,
            );
        }

        $minWait = max(1, $this->resolveMinWaitSeconds($response, $cfg));
        $notBefore = $now->addSeconds($minWait);
        $state = new SitfisProtocolState(
            phase: SitfisProtocolState::PHASE_WAITING_MIN_PERIOD,
            protocol: $protocol,
            requestedAt: $now,
            notBefore: $notBefore,
            pollCount: 0,
            lastPollAt: null,
            correlationId: $correlation,
            requeueAfterSeconds: $minWait,
            simulated: $response->simulated,
        );

        return $this->processingResult(
            state: $state,
            message: 'Protocolo SITFIS obtido; aguardando prazo mínimo oficial.',
            requeueAfter: $minWait,
            evidenceBytes: null,
        );
    }

    /**
     * @param  array{environment: SerproEnvironment, contract: SerproContract, contractor_cnpj: string, author_identity: string, contributor_cnpj: string}  $ids
     * @param  array<string, mixed>  $cfg
     */
    private function emit(
        FiscalAdapterRequest $request,
        array $ids,
        array $cfg,
        SitfisProtocolState $state,
        CarbonImmutable $now,
    ): FiscalAdapterResult {
        $maxPolls = max(1, (int) $cfg['max_polls']);
        $pollInterval = max(5, (int) $cfg['poll_interval_seconds']);

        if ($state->pollCount >= $maxPolls) {
            return new FiscalAdapterResult(
                result: FiscalRunResult::Failed,
                situation: FiscalSituation::Error,
                coverage: FiscalCoverage::Full,
                normalized: [
                    'protocol' => $state->protocol,
                    'poll_count' => $state->pollCount,
                    'is_negative_certificate' => false,
                ],
                progress: $state->with(
                    phase: SitfisProtocolState::PHASE_FAILED,
                )->toProgress(),
                errorCode: 'SITFIS_POLL_EXHAUSTED',
                errorMessage: 'Emissão SITFIS esgotou tentativas de polling respeitoso.',
            );
        }

        $correlation = $state->correlationId ?? $request->run->correlation_id ?? (string) Str::uuid();
        // Oficial: RELATORIOSITFIS92 em /Emitir — campo obrigatório protocoloRelatorio (catálogo 9.2).
        $response = $this->call(
            $request,
            $ids,
            (string) ($cfg['emit_operation_key'] ?? 'sitfis.emitir_relatorio'),
            (string) ($cfg['emit_operation'] ?? 'RELATORIOSITFIS92'),
            businessData: [
                'protocoloRelatorio' => $state->protocol,
            ],
            dadosMode: 'JSON_STRING',
            correlation: $correlation,
        );

        if ($response->hasSimulatedSource()) {
            return FiscalAdapterResult::blocked('Resposta sintética não pode avançar protocolo SITFIS.', 'SIMULATED_SOURCE_REJECTED');
        }

        $pollCount = $state->pollCount + 1;
        $pollInterval = max($pollInterval, $response->waitSeconds() ?? 0);
        $stateAfterPoll = $state->with(
            phase: SitfisProtocolState::PHASE_POLLING_EMIT,
            pollCount: $pollCount,
            lastPollAt: $now,
            correlationId: $correlation,
            simulated: $state->simulated || $response->simulated,
            requeueAfterSeconds: $pollInterval,
        );

        if (! $response->success) {
            if ($this->isGateBlock($response)) {
                return FiscalAdapterResult::blocked(
                    $response->errorMessage ?? 'Elegibilidade/orçamento SITFIS negado.',
                    $response->errorCode ?? 'SITFIS_GATE',
                );
            }

            // 202 / ainda processando mapeado pelo client
            if (in_array($response->httpStatus, [202, 204], true)
                || $response->errorCode === 'STILL_PROCESSING'
                || $this->responseSaysProcessing($response)) {
                return $this->processingResult(
                    state: $stateAfterPoll,
                    message: 'Relatório SITFIS ainda em processamento na fonte.',
                    requeueAfter: $pollInterval,
                );
            }

            return FiscalAdapterResult::failed(
                $response->errorMessage ?? 'Falha na emissão do relatório SITFIS.',
                $response->errorCode ?? 'SITFIS_EMIT_FAILED',
                FiscalCoverage::Full,
            );
        }

        if ($this->responseSaysProcessing($response)) {
            return $this->processingResult(
                state: $stateAfterPoll,
                message: 'Relatório SITFIS ainda em processamento na fonte.',
                requeueAfter: $pollInterval,
            );
        }

        $reportPayload = $this->extractReportPayloadFromResponse($response);
        if ($reportPayload === null) {
            // Sucesso HTTP sem corpo de relatório — trata como ainda processando se marcado
            return $this->processingResult(
                state: $stateAfterPoll,
                message: 'Resposta de emissão sem relatório; reagendando poll respeitoso.',
                requeueAfter: $pollInterval,
            );
        }

        $evidenceBytes = is_string($reportPayload)
            ? $reportPayload
            : json_encode($reportPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

        // Parser versionado — layout desconhecido ainda preserva artefato
        $parsed = $this->parser->parse(
            is_string($reportPayload)
                ? $reportPayload
                : $reportPayload,
        );

        $doneState = $stateAfterPoll->with(
            phase: SitfisProtocolState::PHASE_DONE,
            requeueAfterSeconds: 0,
        );

        $normalized = array_merge($parsed->normalized, [
            'protocol' => $state->protocol,
            'correlation_id' => $correlation,
            'poll_count' => $pollCount,
            'simulated' => $response->simulated,
            'is_negative_certificate' => false,
            'claims_negative_certificate' => false,
        ]);

        // Evidência produtiva: relatório completo (simulado ainda pode ser guardado em trial com flag)
        return new FiscalAdapterResult(
            result: FiscalRunResult::Success,
            situation: $parsed->situation,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidenceBytes,
            evidenceContentType: is_string($reportPayload) && str_starts_with($reportPayload, '%PDF')
                ? 'application/pdf'
                : (is_string($reportPayload) && ! $this->isJson($reportPayload)
                    ? 'application/octet-stream'
                    : 'application/json'),
            sourceVersion: $parsed->parserVersion,
            normalized: $normalized,
            findings: $parsed->findings,
            shouldRequeue: false,
            progressCursor: self::cursorForProtocol($state->protocol),
            progress: $doneState->toProgress(),
            itemsProcessed: count($parsed->findings),
            pagesProcessed: 1,
        );
    }

    /**
     * Cursor curto para a coluna progress_cursor (≤64). Protocolo integral só em progress.protocol.
     */
    public static function cursorForProtocol(?string $protocol): string
    {
        if ($protocol === null || $protocol === '') {
            return 'solicit';
        }

        return 'protocol:'.substr(hash('sha256', $protocol), 0, 16);
    }

    /**
     * @param  array{environment: SerproEnvironment, contract: SerproContract, contractor_cnpj: string, author_identity: string, contributor_cnpj: string}  $ids
     * @param  array<string, mixed>  $businessData
     */
    private function call(
        FiscalAdapterRequest $request,
        array $ids,
        string $operationKey,
        string $legacyOperationCode,
        array $businessData,
        string $dadosMode,
        string $correlation,
        string $idempotencySuffix = '',
    ): IntegraResponse {
        $cfg = $this->config();
        // Domínio/catálogo financeiro: INTEGRA_SITFIS + códigos de domínio
        $domainSystem = (string) ($cfg['system_code'] ?? 'INTEGRA_SITFIS');
        $service = (string) ($cfg['service_code'] ?? 'SITFIS');
        // Elegibilidade/catálogo seed ainda usa códigos de domínio legados no banco
        $catalogOperation = match ($operationKey) {
            'sitfis.solicitar_protocolo' => 'SOLICITAR_RELATORIO',
            'sitfis.emitir_relatorio' => 'EMITIR_RELATORIO',
            default => $legacyOperationCode,
        };
        $env = $ids['environment'];

        $elig = $this->eligibility->evaluate(
            $request->office,
            $request->client,
            $domainSystem,
            $service,
            $catalogOperation,
            $env,
            null,
            'sitfis',
        );

        if (! $elig->eligible) {
            $code = $elig->primaryCode()->value;

            return new IntegraResponse(
                success: false,
                httpStatus: 422,
                body: [],
                errorCode: $code,
                errorMessage: 'Elegibilidade Integra negada: '.$code,
                correlationId: $correlation,
                operationKey: $operationKey,
            );
        }

        $this->eligibility->touchRateLimit((int) $request->office->id);

        $cursor = $request->progressCursor ?? '0';
        if ($idempotencySuffix !== '') {
            $cursor .= ':'.$idempotencySuffix;
        }
        $idempotencyKey = $request->run->idempotency_key.':'.$operationKey.':'.$cursor;
        $payload = $dadosMode === 'EMPTY'
            ? ['dados' => '']
            : ['dados' => json_encode($businessData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];

        $integraRequest = new IntegraRequest(
            officeId: (int) $request->office->id,
            clientId: (int) $request->client->id,
            environment: $env->value,
            contractorCnpj: $ids['contractor_cnpj'],
            authorIdentity: $ids['author_identity'],
            contributorCnpj: $ids['contributor_cnpj'],
            operationKey: $operationKey,
            solutionCode: $domainSystem,
            serviceCode: $service,
            operationCode: $legacyOperationCode,
            businessData: $businessData,
            payload: $payload,
            idempotencyKey: $idempotencyKey,
            correlationId: $correlation,
        );

        // Egress único via executor central (gates, ledger, attempt, request-tag).
        return $this->operations->executeRequest($integraRequest, module: 'sitfis');
    }

    private function isGateBlock(IntegraResponse $response): bool
    {
        $code = (string) ($response->errorCode ?? '');

        return in_array($code, [
            'BUDGET_EXCEEDED',
            'FEATURE_DISABLED',
            'CAPABILITY_DISABLED',
            'KILL_SWITCH',
            'CIRCUIT_OPEN',
            'SUBSCRIPTION_BLOCKED',
            'CONTRACT_UNAVAILABLE',
            'CONTRACT_UNHEALTHY',
            'AUTHORIZATION_MISSING',
            'AUTHORIZATION_ACTION_REQUIRED',
            'AUTHORIZATION_EXPIRED',
            'TOKEN_MISSING',
            'TOKEN_EXPIRED',
            'CONTRIBUTOR_CROSS_TENANT',
            'PROXY_POWER_MISSING',
            'PROXY_POWER_INSUFFICIENT',
            'PROXY_POWER_EXPIRED',
            'COVERAGE_UNSUPPORTED',
            'ROLE_FORBIDDEN',
            'RATE_LIMITED',
            'SERVICE_NOT_CATALOGED',
            'MUTATING_DISABLED',
        ], true);
    }

    private function processingResult(
        SitfisProtocolState $state,
        string $message,
        int $requeueAfter,
        ?string $evidenceBytes = null,
    ): FiscalAdapterResult {
        $progress = $state->with(requeueAfterSeconds: $requeueAfter)->toProgress();

        return new FiscalAdapterResult(
            result: FiscalRunResult::Partial,
            situation: FiscalSituation::Processing,
            coverage: FiscalCoverage::Full,
            evidenceBytes: $evidenceBytes,
            sourceVersion: (string) config('fiscal_monitoring.sitfis.parser_version', SitfisReportParser::VERSION),
            normalized: [
                'phase' => $state->phase,
                'protocol' => $state->protocol,
                'message' => $message,
                'is_negative_certificate' => false,
            ],
            findings: [[
                'code' => 'SITFIS_PROCESSING',
                'severity' => FiscalFindingSeverity::Info->value,
                'title' => 'Situação fiscal em processamento',
                'detail' => $message,
                'situation' => FiscalSituation::Processing->value,
                'creates_pending' => false,
            ]],
            shouldRequeue: true,
            progressCursor: self::cursorForProtocol($state->protocol),
            progress: $progress,
            requeueAfterSeconds: $requeueAfter,
        );
    }

    private function extractProtocolFromResponse(IntegraResponse $response): ?string
    {
        // Preferir dados parseados do envelope Integra (campo oficial pós-pedidoDados).
        $dadosNormalized = $this->normalizeDadosPayload($response->dados);
        if ($dadosNormalized !== null) {
            $fromDados = $this->extractProtocol($dadosNormalized);
            if ($fromDados !== null && $fromDados !== '') {
                return $fromDados;
            }
        }

        $fromBody = $this->extractProtocol($response->body);
        if ($fromBody !== null && $fromBody !== '') {
            return $fromBody;
        }

        // Doc SERPRO (cache /Apoiar): 304 com body vazio e protocolo no ETag.
        return $this->extractProtocolFromEtag($response->etag);
    }

    /**
     * Extrai protocoloRelatorio do header ETag oficial SITFIS.
     * Formato: protocoloRelatorio:{token} (aspas opcionais).
     */
    private function extractProtocolFromEtag(?string $etag): ?string
    {
        if ($etag === null) {
            return null;
        }

        $raw = trim($etag);
        if ($raw === '') {
            return null;
        }

        $raw = trim($raw, "\"'");
        if (! preg_match('/protocoloRelatorio\s*[=:]\s*(.+)$/i', $raw, $matches)) {
            return null;
        }

        $token = trim($matches[1], " \t\n\r\0\x0B\"'");
        if ($token === '' || str_contains($token, 'omitted_from_attempt_store')) {
            return null;
        }

        return $token;
    }

    /**
     * @param  array{environment: SerproEnvironment, contract: SerproContract, contractor_cnpj: string, author_identity: string, contributor_cnpj: string}  $ids
     * @param  array<string, mixed>  $cfg
     */
    private function ensureProxyPower(FiscalAdapterRequest $request, array $ids, array $cfg): ?FiscalAdapterResult
    {
        $power = trim((string) ($cfg['required_proxy_power'] ?? '00002'));
        if ($power === '') {
            return null;
        }

        $ensure = $this->procuracaoEnsure->ensure(
            $request->office,
            $request->client,
            $ids['environment'],
            [$power],
            $request->run->triggered_by !== null ? (int) $request->run->triggered_by : null,
        );

        if ($ensure['ok']) {
            return null;
        }

        $code = $ensure['code'] ?? 'PROXY_POWER_MISSING';

        return FiscalAdapterResult::blocked(
            $ensure['message'] ?? ('Elegibilidade Integra negada: '.$code),
            $code,
        );
    }

    /**
     * Normaliza `dados` do envelope (objeto, JSON string ou lista com 1 item).
     *
     * @return array<string, mixed>|null
     */
    private function normalizeDadosPayload(mixed $dados): ?array
    {
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            $dados = is_array($decoded) ? $decoded : null;
        }
        if (! is_array($dados) || $dados === []) {
            return null;
        }
        // Oficial às vezes devolve lista: [{"pdf":"..."}] ou [{"protocoloRelatorio":"..."}]
        if (array_is_list($dados)) {
            foreach ($dados as $item) {
                if (is_array($item) && $item !== []) {
                    return $item;
                }
            }

            return null;
        }

        return $dados;
    }

    /**
     * Extrai protocolo do payload SITFIS (oficial: protocoloRelatorio).
     *
     * @param  array<string, mixed>  $body
     */
    private function extractProtocol(array $body): ?string
    {
        $keys = [
            'protocoloRelatorio',
            'protocolo_relatorio',
            'protocolo',
            'protocol',
            'numeroProtocolo',
            'protocolNumber',
            'numero_protocolo',
        ];

        $scalar = $this->firstScalarProtocol($body, $keys);
        if ($scalar !== null) {
            return $scalar;
        }

        foreach (['dados', 'resultado', 'data', 'pedidoDados'] as $wrap) {
            if (! isset($body[$wrap])) {
                continue;
            }
            $inner = $body[$wrap];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (is_array($decoded)) {
                    $inner = $decoded;
                }
            }
            if (! is_array($inner)) {
                continue;
            }
            $found = $this->firstScalarProtocol($inner, $keys);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function firstScalarProtocol(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if (is_scalar($value) && trim((string) $value) !== '' && ! is_bool($value)) {
                // Sanitizers de attempt-store não devem vazar como protocolo.
                if (is_string($value) && str_contains($value, 'omitted_from_attempt_store')) {
                    continue;
                }

                return trim((string) $value);
            }
            // Alguns contratos aninham { "numero": "..." } ou similar.
            if (is_array($value)) {
                foreach (['numero', 'valor', 'id', 'protocolo', 'protocoloRelatorio'] as $nested) {
                    if (! empty($value[$nested]) && is_scalar($value[$nested])) {
                        $s = trim((string) $value[$nested]);
                        if ($s !== '' && ! str_contains($s, 'omitted_from_attempt_store')) {
                            return $s;
                        }
                    }
                }
            }
        }

        return null;
    }

    private function isEmptyNotModified(IntegraResponse $response): bool
    {
        if ($response->httpStatus === 304 || $response->errorCode === 'NOT_MODIFIED') {
            return true;
        }
        if ($response->businessStatus === 'NOT_MODIFIED') {
            return true;
        }

        return false;
    }

    private function isWaitingCacheExpiry(SitfisProtocolState $state, CarbonImmutable $now): bool
    {
        if ($state->phase !== SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY) {
            return false;
        }
        if ($state->hasProtocol()) {
            return false;
        }
        if ($state->notBefore === null) {
            return false;
        }

        return $now->lessThan($state->notBefore);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function resolveCacheWaitSeconds(IntegraResponse $response, array $cfg): int
    {
        $fallback = max(60, (int) ($cfg['cache_empty_fallback_seconds'] ?? 900));
        $max = max($fallback, (int) ($cfg['cache_empty_max_wait_seconds'] ?? 86400));

        $expires = $response->expiresHeader;
        if ($expires === null || trim($expires) === '') {
            $expires = null;
            foreach ($response->headers as $key => $value) {
                if (strcasecmp((string) $key, 'expires') === 0 && is_scalar($value)) {
                    $candidate = trim((string) $value);
                    if ($candidate !== '') {
                        $expires = $candidate;
                    }
                    break;
                }
            }
        }

        if ($expires !== null && trim($expires) !== '') {
            try {
                $until = CarbonImmutable::parse(trim($expires));
                $wait = (int) CarbonImmutable::now()->diffInSeconds($until, false);
                if ($wait > 0) {
                    return min($max, max(60, $wait + 5));
                }
            } catch (\Throwable) {
                // fallback abaixo
            }
        }

        return min($max, $fallback);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function resolveMinWaitSeconds(IntegraResponse $response, array $cfg): int
    {
        $fallback = max(1, (int) ($cfg['min_wait_seconds'] ?? 30));
        $fromResponse = $response->waitSeconds();

        // Oficial SITFIS frequentemente envia tempoEspera em milissegundos (ex.: 4000).
        $rawTempo = null;
        if (is_array($response->dados) && isset($response->dados['tempoEspera']) && is_numeric($response->dados['tempoEspera'])) {
            $rawTempo = (int) $response->dados['tempoEspera'];
        } elseif (isset($response->body['tempoEspera']) && is_numeric($response->body['tempoEspera'])) {
            $rawTempo = (int) $response->body['tempoEspera'];
        } elseif (isset($response->body['dados']) && is_array($response->body['dados'])
            && isset($response->body['dados']['tempoEspera']) && is_numeric($response->body['dados']['tempoEspera'])) {
            $rawTempo = (int) $response->body['dados']['tempoEspera'];
        }

        if ($rawTempo !== null && $rawTempo > 0) {
            // Heurística: valores grandes sem sufixo EmMs são milissegundos.
            if ($rawTempo > 180) {
                return max(1, (int) ceil($rawTempo / 1000));
            }

            return max(1, $rawTempo);
        }

        return max(1, $fromResponse ?? $fallback);
    }

    private function responseSaysProcessing(IntegraResponse $response): bool
    {
        if ($response->isStillProcessing()) {
            return true;
        }
        if (is_array($response->dados) && $this->bodySaysProcessing($response->dados)) {
            return true;
        }

        return $this->bodySaysProcessing($response->body);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function bodySaysProcessing(array $body): bool
    {
        $status = strtoupper((string) ($body['status'] ?? $body['situacao'] ?? $body['estado'] ?? ''));
        if (in_array($status, ['PROCESSING', 'PROCESSANDO', 'PENDENTE', 'EM_PROCESSAMENTO', 'AGUARDANDO'], true)) {
            return true;
        }

        foreach (['dados', 'resultado', 'data'] as $wrap) {
            if (! isset($body[$wrap])) {
                continue;
            }
            $inner = $body[$wrap];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                $inner = is_array($decoded) ? $decoded : null;
            }
            if (! is_array($inner)) {
                continue;
            }
            $s = strtoupper((string) ($inner['status'] ?? $inner['situacao'] ?? ''));
            if (in_array($s, ['PROCESSING', 'PROCESSANDO', 'PENDENTE', 'EM_PROCESSAMENTO', 'AGUARDANDO'], true)) {
                return true;
            }
            if (! empty($inner['processando']) || ! empty($inner['processing'])) {
                return true;
            }
        }

        return ! empty($body['processando']) || ! empty($body['processing']);
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private function extractReportPayloadFromResponse(IntegraResponse $response): array|string|null
    {
        $dadosNormalized = $this->normalizeDadosPayload($response->dados);
        if ($dadosNormalized !== null && ! $this->bodySaysProcessing($dadosNormalized)) {
            $fromDados = $this->extractReportPayload($dadosNormalized);
            if ($fromDados !== null) {
                return $fromDados;
            }
            // dados é o relatório em si (pendências / layout)
            if (isset($dadosNormalized['pendencias']) || isset($dadosNormalized['itens'])
                || isset($dadosNormalized['layoutVersion']) || isset($dadosNormalized['layout_version'])
                || isset($dadosNormalized['pdf']) || isset($dadosNormalized['pdfBase64'])) {
                return $dadosNormalized;
            }
        }

        return $this->extractReportPayload($response->body);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|string|null
     */
    private function extractReportPayload(array $body): array|string|null
    {
        // Lista oficial: [{"pdf":"..."}]
        if (array_is_list($body)) {
            foreach ($body as $item) {
                if (is_array($item) && $item !== []) {
                    $nested = $this->extractReportPayload($item);
                    if ($nested !== null) {
                        return $nested;
                    }
                }
            }

            return null;
        }

        // Oficial RELATORIOSITFIS92: campo pdf (base64) em status 200.
        foreach (['pdf', 'relatorio', 'report', 'conteudo', 'pdfBase64', 'arquivo', 'relatorioPdf', 'relatorioBase64'] as $key) {
            if (! array_key_exists($key, $body)) {
                continue;
            }
            $v = $body[$key];
            if (is_string($v) && $v !== '') {
                // PDF base64 ou JSON de relatório
                if ($key === 'pdf' || $key === 'pdfBase64' || $key === 'relatorioPdf' || $key === 'relatorioBase64') {
                    $binary = $this->tryDecodeBase64Pdf($v);
                    if ($binary !== null) {
                        return $binary;
                    }
                }
                $decoded = json_decode($v, true);
                if (is_array($decoded)) {
                    return $decoded;
                }

                return $v;
            }
            if (is_array($v) && $v !== []) {
                return $v;
            }
        }

        foreach (['dados', 'resultado', 'data'] as $wrap) {
            if (! isset($body[$wrap])) {
                continue;
            }
            $inner = $body[$wrap];
            if (is_string($inner) && $inner !== '') {
                $decoded = json_decode($inner, true);
                if (is_array($decoded)) {
                    if ($this->bodySaysProcessing($decoded)) {
                        return null;
                    }
                    $fromInner = $this->extractReportPayload($decoded);
                    if ($fromInner !== null) {
                        return $fromInner;
                    }

                    return $decoded;
                }

                return $inner;
            }
            if (is_array($inner) && $inner !== []) {
                if ($this->bodySaysProcessing($inner)) {
                    return null;
                }
                $fromInner = $this->extractReportPayload($inner);
                if ($fromInner !== null) {
                    return $fromInner;
                }
                // Se for só status, não é relatório
                if (count($inner) === 1 && isset($inner['status'])) {
                    return null;
                }
                // Protocol-only payload (fase solicit) não é relatório
                if ($this->extractProtocol($inner) !== null && count($inner) <= 3
                    && ! isset($inner['pendencias']) && ! isset($inner['relatorio']) && ! isset($inner['pdf'])) {
                    return null;
                }

                return $inner;
            }
        }

        // Corpo inteiro parece relatório (tem pendencias/itens/layout)
        if (isset($body['pendencias']) || isset($body['itens']) || isset($body['layoutVersion'])
            || isset($body['layout_version']) || isset($body['__unknown_layout'])) {
            return $body;
        }

        return null;
    }

    private function tryDecodeBase64Pdf(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        // Já é binário PDF
        if (str_starts_with($trimmed, '%PDF')) {
            return $trimmed;
        }
        $decoded = base64_decode($trimmed, true);
        if ($decoded === false || $decoded === '') {
            return null;
        }
        if (str_starts_with($decoded, '%PDF')) {
            return $decoded;
        }

        // Base64 válido mas não PDF — devolve bytes para evidência (parser marca layout desconhecido).
        return $decoded;
    }

    private function isJson(string $value): bool
    {
        json_decode($value);

        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        return (array) config('fiscal_monitoring.sitfis', []);
    }
}
