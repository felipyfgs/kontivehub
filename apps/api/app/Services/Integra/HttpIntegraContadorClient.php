<?php

namespace App\Services\Integra;

use App\Contracts\IntegraContadorClient;
use App\Contracts\SecureObjectStore;
use App\Contracts\SerproContractAuthenticator;
use App\DTO\Serpro\FiscalIdentity;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproEnvironment;
use App\Enums\SerproFunctionalRoute;
use App\Models\ClientProcuracaoSnapshot;
use App\Models\OfficeSerproAuthorization;
use App\Models\TaxProxyPower;
use App\Services\Serpro\Catalog\OperationCoordinateResolver;
use App\Services\Serpro\SerproCircuitBreaker;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproHttpTransport;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproRateLimiter;
use RuntimeException;
use Throwable;

/**
 * Client HTTP real — rotas funcionais, envelope oficial, headers sanitizados.
 * Cadeia: Contratante (Bearer+jwt_token) → Autor (autenticar_procurador_token) → Contribuinte.
 */
final class HttpIntegraContadorClient implements IntegraContadorClient
{
    /** Headers permitidos além dos oficiais do contrato/procurador. */
    private const HEADER_ALLOWLIST = [
        'if-none-match',
        'accept',
        'content-type',
        'x-correlation-id',
    ];

    private const OFFICIAL_HEADER_NAMES = [
        'authorization',
        'jwt_token',
        'autenticar_procurador_token',
        'x-request-tag',
        'content-type',
        'accept',
    ];

    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly SerproContractAuthenticator $authenticator,
        private readonly SerproHttpTransport $transport,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproCircuitBreaker $breaker,
        private readonly SecureObjectStore $store,
        private readonly ?OperationCoordinateResolver $coordinates = null,
        private readonly ?SerproRateLimiter $rateLimiter = null,
        private readonly ?IntegraResponseNormalizer $responseNormalizer = null,
    ) {}

    public function execute(IntegraRequest $request): IntegraResponse
    {
        $operationKey = $request->operationKey;
        $requestTag = $request->resolvedRequestTag();

        try {
            $coords = ($this->coordinates ?? app(OperationCoordinateResolver::class))
                ->resolveExecutable($operationKey);
        } catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'CAPABILITY_NOT_IMPLEMENTED')
                || str_contains($e->getMessage(), 'CAPABILITY_NOT_EXECUTABLE')
            ) {
                return $this->fail($request, 422, 'CAPABILITY_NOT_IMPLEMENTED', $e->getMessage());
            }
            throw $e;
        }

        $solutionForBreaker = (string) $coords['id_sistema'];
        $routeEnum = $coords['route'] instanceof SerproFunctionalRoute
            ? $coords['route']
            : SerproFunctionalRoute::from((string) $coords['route']);
        $routePath = $routeEnum->path();
        $routeName = $routeEnum->value;
        $isMutating = (bool) ($coords['is_mutating'] || $request->isMutating);

        if ($this->killSwitch->isSolutionBlocked($solutionForBreaker)) {
            return $this->fail($request, 503, 'KILL_SWITCH', 'Integra Contador temporariamente desabilitado.');
        }

        if (! $this->breaker->isCallAllowed($solutionForBreaker)) {
            return $this->fail($request, 503, 'CIRCUIT_OPEN', 'Circuit breaker aberto para a solução.');
        }

        try {
            ($this->rateLimiter ?? app(SerproRateLimiter::class))->attempt($request->officeId, $operationKey);
        } catch (RuntimeException $e) {
            return $this->fail($request, 429, 'RATE_LIMIT_LOCAL', $e->getMessage());
        }

        $env = SerproEnvironment::from($request->environment);
        $procuradorToken = null;
        // Trial e produção usam exclusivamente o contrato ativo persistido no
        // banco e seu material no SecureObjectStore. Nenhum segredo de gateway
        // é lido do .env.
        $contract = $this->contracts->activeFor($env);
        if ($contract === null || ! $contract->isUsable()) {
            return $this->fail($request, 503, 'CONTRACT_UNAVAILABLE', 'Contrato SERPRO indisponível.');
        }
        $contractorCnpj = $contract->contractor_cnpj;
        if ($contractorCnpj !== $request->contractorCnpj) {
            return $this->fail($request, 422, 'CONTRACTOR_MISMATCH', 'Identidade contratante diverge do contrato ativo.');
        }

        // O Trial oficial usa objetos mock e não exige token/poder de
        // procurador. A cadeia de representação é validada em produção.
        if ($env === SerproEnvironment::Production) {
            $authMode = (string) ($coords['auth_mode'] ?? 'PROCURATOR_WHEN_REPRESENTING');
            $proxyRule = (string) ($coords['proxy_rule'] ?? 'NOT_APPLICABLE');
            $isAutenticaProcurador = $authMode === 'CONTRACT_ONLY'
                || $operationKey === 'autentica_procurador.envio_xml_assinado';

            // Poder e-CAC e token local de procurador só existem no fluxo contratual produtivo.
            $powerCheck = $this->assertProxyPower($request, $coords, $proxyRule);
            if ($powerCheck !== null) {
                return $powerCheck;
            }

            /** @var list<string> $requiredPowers */
            $requiredPowers = $coords['required_proxy_powers'] ?? [];
            $needsProcuradorToken = $this->requiresProcuradorToken(
                $authMode,
                $proxyRule,
                $request,
                $isAutenticaProcurador,
                $requiredPowers,
            );
            if ($needsProcuradorToken) {
                $resolved = $this->resolveProcuradorToken($request, $env);
                if ($resolved['token'] === null) {
                    return $this->fail(
                        $request,
                        422,
                        $resolved['code'],
                        $resolved['message'],
                    );
                }
                $procuradorToken = $resolved['token'];
            }
        }

        if ($env === SerproEnvironment::Trial) {
            try {
                $tokenType = 'Bearer';
                $accessToken = $this->contracts->loadTrialGatewayBearer($contract);
                $jwtToken = '';
            } catch (Throwable) {
                return $this->fail(
                    $request,
                    503,
                    'TRIAL_CREDENTIALS_MISSING',
                    'Bearer do gateway Trial ausente no banco/cofre.',
                );
            }
        } else {
            try {
                $token = $this->authenticator->authenticate($contract);
                $token->assertComplete();
                $tokenType = $token->tokenType;
                $accessToken = $token->accessToken;
                $jwtToken = (string) $token->officialJwt();
            } catch (Throwable $e) {
                return $this->fail(
                    $request,
                    503,
                    'CONTRACT_UNHEALTHY',
                    'Falha de autenticação do contrato: '.$e->getMessage(),
                );
            }
        }

        $idSistema = (string) $coords['id_sistema'];
        $idServico = (string) $coords['id_servico'];
        $versao = (string) $coords['versao_sistema'];
        $dadosMode = (string) ($coords['dados_mode'] ?? 'JSON_STRING');

        $dadosString = $this->serializeDados($request, $dadosMode);
        $envelopeContractor = ['numero' => $contractorCnpj, 'tipo' => 2];
        $envelopeAuthor = $request->author->toEnvelope();
        $envelopeContributor = $request->contributorEnvelope ?? $request->contributor->toEnvelope();

        if ($env === SerproEnvironment::Trial) {
            $scenario = $this->trialScenario($operationKey);
            if ($scenario === null) {
                return $this->fail(
                    $request,
                    422,
                    'TRIAL_SCENARIO_UNAVAILABLE',
                    'A operação não possui cenário oficial publicado para o gateway Trial.',
                );
            }
            $envelopeContractor = $scenario['identity'];
            $envelopeAuthor = $scenario['identity'];
            $envelopeContributor = $scenario['identity'];
            $dadosString = json_encode($scenario['business_data'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        $envelope = [
            'contratante' => $envelopeContractor,
            'autorPedidoDados' => $envelopeAuthor,
            'contribuinte' => $envelopeContributor,
            'pedidoDados' => [
                'idSistema' => $idSistema,
                'idServico' => $idServico,
                'versaoSistema' => $versao,
                'dados' => $dadosString,
            ],
        ];

        $body = json_encode($envelope, JSON_THROW_ON_ERROR);
        $baseUrl = rtrim((string) config('serpro.environments.'.$env->value.'.base_url'), '/');
        if ($baseUrl === '') {
            return $this->fail($request, 503, 'ENDPOINT_UNAVAILABLE', 'Endpoint SERPRO do ambiente não configurado.');
        }
        $url = $baseUrl.$routePath;

        $attempt = 0;
        $maxAttempts = $env === SerproEnvironment::Production ? 2 : 1;
        $lastResponse = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $headers = $this->buildHeaders($tokenType, $accessToken, $jwtToken, $requestTag, $procuradorToken, $request->headers);

            try {
                $lastResponse = $this->transport->request(
                    'POST',
                    $url,
                    null,
                    $body,
                    $headers,
                    $request->correlationId,
                );
            } catch (Throwable $e) {
                // Timeout/falha ambígua em mutação: NÃO retry automático
                if ($isMutating) {
                    return new IntegraResponse(
                        success: false,
                        httpStatus: 0,
                        body: [],
                        errorCode: 'MUTATION_TIMEOUT_PENDING',
                        errorMessage: 'Timeout ambíguo em mutação — pendente de conciliação.',
                        correlationId: $request->correlationId,
                        operationKey: $operationKey,
                        requestTag: $requestTag,
                        functionalRoute: $routeName,
                        // Trial é gateway externo oficial, não double local.
                        simulated: false,
                        sourceProvenance: $this->provenanceFor($env),
                    );
                }
                throw new RuntimeException('Falha de transporte Integra Contador.', 0, $e);
            }

            if ($env === SerproEnvironment::Production && $lastResponse['status'] === 401 && $attempt < $maxAttempts) {
                $this->authenticator->invalidate($contract);
                try {
                    $token = $this->authenticator->authenticate($contract);
                    $token->assertComplete();
                    $tokenType = $token->tokenType;
                    $accessToken = $token->accessToken;
                    $jwtToken = (string) $token->officialJwt();
                } catch (Throwable $e) {
                    return $this->fail(
                        $request,
                        503,
                        'CONTRACT_UNHEALTHY',
                        'Falha ao renovar OAuth após 401: '.$e->getMessage(),
                    );
                }

                continue; // mesma tag, mesma body
            }

            break;
        }

        return ($this->responseNormalizer ?? app(IntegraResponseNormalizer::class))->normalize(
            $lastResponse ?? ['status' => 0, 'body' => '', 'headers' => [], 'retry_after' => null, 'latency_ms' => null],
            $request,
            $operationKey,
            $requestTag,
            $routeName,
            $env,
        );
    }

    /**
     * @param  list<string>  $requiredPowers
     */
    private function requiresProcuradorToken(
        string $authMode,
        string $proxyRule,
        IntegraRequest $request,
        bool $isAutenticaProcurador,
        array $requiredPowers = [],
    ): bool {
        if ($isAutenticaProcurador || $authMode === 'CONTRACT_ONLY') {
            return false;
        }

        if ($authMode === 'PROCURATOR_REQUIRED' || $proxyRule === 'REQUIRED') {
            return true;
        }

        $isRepresenting = $request->author->numero !== $request->contributor->numero;
        if (! $isRepresenting) {
            return false;
        }

        // Representação: token quando catálogo exige poder/proxy ou auth_mode de procurador
        if ($proxyRule === 'NOT_APPLICABLE' && $requiredPowers === [] && $authMode === '') {
            return false;
        }

        if ($proxyRule === 'NOT_APPLICABLE' && $requiredPowers === []
            && ! in_array($authMode, ['PROCURATOR_WHEN_REPRESENTING', 'PROCURATOR_REQUIRED'], true)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $coords
     */
    private function assertProxyPower(IntegraRequest $request, array $coords, string $proxyRule): ?IntegraResponse
    {
        if (in_array($proxyRule, ['NOT_APPLICABLE', 'EVENT_DEPENDENT'], true)) {
            return null;
        }

        /** @var list<string> $powers */
        $powers = $coords['required_proxy_powers'] ?? [];
        if ($powers === [] && ! empty($coords['required_proxy_power'])) {
            $powers = preg_split('/[\s,]+/', (string) $coords['required_proxy_power']) ?: [];
        }
        $powers = array_values(array_filter(array_map('strval', $powers)));
        if ($powers === []) {
            return null;
        }

        // Só exige poder quando a relação autor–contribuinte implica representação
        if ($proxyRule === 'REQUIRED_WHEN_REPRESENTING'
            && $request->author->numero === $request->contributor->numero
        ) {
            return null;
        }

        // Preferir projeção oficial ClientProcuracaoSnapshot quando existir.
        try {
            $snapshot = ClientProcuracaoSnapshot::query()
                ->where('office_id', $request->officeId)
                ->where('client_id', $request->clientId)
                ->where('environment', $request->environment)
                ->first();

            if ($snapshot !== null) {
                if ($snapshot->status === ClientProcuracaoSyncStatus::Expired) {
                    return $this->fail(
                        $request,
                        422,
                        'PROXY_POWER_EXPIRED',
                        'Procuração vencida para a operação.',
                    );
                }
                if ($snapshot->status === ClientProcuracaoSyncStatus::Missing) {
                    return $this->fail(
                        $request,
                        422,
                        'PROXY_POWER_MISSING',
                        'Poder e-CAC obrigatório ausente: '.implode(',', $powers),
                    );
                }
                if ($snapshot->status === ClientProcuracaoSyncStatus::Authorized
                    && $snapshot->isUsableForRequiredPower()
                ) {
                    return null;
                }
            }

            $has = TaxProxyPower::query()
                ->where('office_id', $request->officeId)
                ->where('client_id', $request->clientId)
                ->whereIn('power_code', $powers)
                ->where(function ($q): void {
                    $q->whereNull('valid_to')->orWhere('valid_to', '>', now());
                })
                ->exists();
        } catch (Throwable) {
            return $this->fail(
                $request,
                503,
                'PROXY_POWER_UNAVAILABLE',
                'Não foi possível validar o poder e-CAC obrigatório.',
            );
        }

        if (! $has) {
            return $this->fail(
                $request,
                422,
                'PROXY_POWER_MISSING',
                'Poder e-CAC obrigatório ausente: '.implode(',', $powers),
            );
        }

        return null;
    }

    /**
     * @param  array<string, string>  $extraHeaders
     * @return list<string>
     */
    private function buildHeaders(
        string $tokenType,
        string $accessToken,
        string $jwt,
        string $requestTag,
        ?string $procuradorToken,
        array $extraHeaders,
    ): array {
        $headers = [
            'Authorization: '.$tokenType.' '.$accessToken,
            'X-Request-Tag: '.substr($requestTag, 0, 32),
            'Content-Type: application/json',
            'Accept: application/json',
        ];
        // O gateway de demonstração documenta jwt_token como opcional. Em
        // produção o token sempre vem do OAuth e assertComplete() o exige.
        if ($jwt !== '') {
            $headers[] = 'jwt_token: '.$jwt;
        }
        if ($procuradorToken !== null && $procuradorToken !== '') {
            $headers[] = 'autenticar_procurador_token: '.$procuradorToken;
        }

        foreach ($extraHeaders as $name => $value) {
            $ln = strtolower((string) $name);
            if (in_array($ln, self::OFFICIAL_HEADER_NAMES, true)) {
                continue; // não sobrescreve oficiais
            }
            if (! in_array($ln, self::HEADER_ALLOWLIST, true)) {
                continue; // descarta header arbitrário
            }
            if (preg_match('/[\x00-\x1F\x7F]/', (string) $name.(string) $value) === 1) {
                continue; // impede header injection por CR/LF ou controles
            }
            $headers[] = $name.': '.$value;
        }

        return $headers;
    }

    private function serializeDados(IntegraRequest $request, string $dadosMode): string
    {
        if ($dadosMode === 'EMPTY') {
            return '';
        }

        // Preferir businessData — serializado exatamente uma vez
        if ($request->businessData !== []) {
            $data = $request->businessData;
            unset($data['__scenario']);

            return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }

        if (isset($request->payload['dados']) && is_string($request->payload['dados'])) {
            // Já serializado uma vez — não re-escapar
            return $request->payload['dados'];
        }

        if (isset($request->payload['pedidoDados']['dados']) && is_string($request->payload['pedidoDados']['dados'])) {
            return $request->payload['pedidoDados']['dados'];
        }

        $legacy = $request->payload;
        unset($legacy['idSistema'], $legacy['idServico'], $legacy['versaoSistema'], $legacy['dados']);
        if ($legacy === [] && isset($request->payload['dados']) && is_array($request->payload['dados'])) {
            $legacy = $request->payload['dados'];
        }

        if ($legacy === []) {
            return '';
        }

        return json_encode($legacy, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array{identity: array{numero: string, tipo: int}, business_data: array<string, mixed>}|null
     */
    private function trialScenario(string $operationKey): ?array
    {
        $scenarios = config('serpro.environments.TRIAL.scenarios', []);
        $scenario = is_array($scenarios) ? ($scenarios[$operationKey] ?? null) : null;
        if (! is_array($scenario) || ! is_array($scenario['identity'] ?? null)
            || ! is_array($scenario['business_data'] ?? null)
        ) {
            return null;
        }

        $numero = (string) ($scenario['identity']['numero'] ?? '');
        $tipo = (int) ($scenario['identity']['tipo'] ?? 0);
        if ($numero === '' || $tipo < 1) {
            return null;
        }

        return [
            'identity' => ['numero' => $numero, 'tipo' => $tipo],
            'business_data' => $scenario['business_data'],
        ];
    }

    private function fail(
        IntegraRequest $request,
        int $http,
        string $code,
        string $message,
    ): IntegraResponse {
        return new IntegraResponse(
            success: false,
            httpStatus: $http,
            body: [],
            errorCode: $code,
            errorMessage: $message,
            correlationId: $request->correlationId,
            operationKey: $request->operationKey,
            requestTag: $request->resolvedRequestTag(),
            simulated: false,
            sourceProvenance: $this->provenanceFor(SerproEnvironment::tryFrom($request->environment)),
        );
    }

    private function provenanceFor(?SerproEnvironment $environment): string
    {
        return $environment === SerproEnvironment::Trial
            ? FiscalSourceProvenance::SerproTrial->value
            : FiscalSourceProvenance::SerproReal->value;
    }

    /**
     * @return array{token: ?string, code: string, message: string}
     */
    private function resolveProcuradorToken(IntegraRequest $request, SerproEnvironment $env): array
    {
        $auth = OfficeSerproAuthorization::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->officeId)
            ->where('environment', $env->value)
            ->first();

        if ($auth === null) {
            return $this->tokenFailure(
                'AUTHORIZATION_MISSING',
                'Autorização SERPRO do escritório ausente para o ambiente.',
            );
        }

        if ($auth->procurador_token_vault_object_id === null) {
            return $this->tokenFailure(
                'PROCURADOR_TOKEN_MISSING',
                'Token do procurador ainda não foi obtido para o escritório.',
            );
        }

        if ($auth->procurador_token_expires_at === null) {
            return $this->tokenFailure(
                'PROCURADOR_TOKEN_MISSING',
                'Validade do token do procurador ausente; renove a autorização.',
            );
        }

        if ($auth->procurador_token_expires_at->isPast()) {
            return $this->tokenFailure(
                'PROCURADOR_TOKEN_EXPIRED',
                'Token do procurador expirado; renove a autorização do escritório.',
            );
        }

        $authorRaw = strtoupper(trim((string) $auth->author_identity));
        if ($authorRaw === '' || $authorRaw === '00000000000000') {
            return $this->tokenFailure(
                'AUTHOR_IDENTITY_MISSING',
                'Autor do Pedido não configurado na autorização do escritório.',
            );
        }

        try {
            $authorNormalized = FiscalIdentity::fromNumero($authorRaw)->numero;
        } catch (Throwable) {
            return $this->tokenFailure(
                'AUTHOR_IDENTITY_MISSING',
                'Autor do Pedido inválido na autorização do escritório.',
            );
        }

        if ($request->authorIdentity !== $authorNormalized) {
            return $this->tokenFailure(
                'AUTHOR_IDENTITY_MISMATCH',
                'Autor do pedido diverge da autorização SERPRO do escritório.',
            );
        }

        $aad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
            'office_id' => $auth->office_id,
            'environment' => $env->value,
            'author_identity' => $auth->author_identity,
        ]);

        try {
            $raw = $this->store->get($auth->procurador_token_vault_object_id, $aad);
            /** @var array{token?: string}|null $payload */
            $payload = json_decode($raw, true);
            $token = is_array($payload) ? (string) ($payload['token'] ?? '') : '';
            if ($token === '') {
                return $this->tokenFailure(
                    'PROCURADOR_TOKEN_EMPTY',
                    'Material do token do procurador no cofre está vazio.',
                );
            }

            return ['token' => $token, 'code' => '', 'message' => ''];
        } catch (Throwable) {
            return $this->tokenFailure(
                'PROCURADOR_TOKEN_VAULT_UNREADABLE',
                'Falha ao ler o token do procurador no cofre do escritório.',
            );
        }
    }

    /**
     * @return array{token: null, code: string, message: string}
     */
    private function tokenFailure(string $code, string $message): array
    {
        return ['token' => null, 'code' => $code, 'message' => $message];
    }

    /** @deprecated Use resolveProcuradorToken(); mantido para testes legados. */
    private function loadProcuradorToken(IntegraRequest $request, SerproEnvironment $env): ?string
    {
        return $this->resolveProcuradorToken($request, $env)['token'];
    }
}
