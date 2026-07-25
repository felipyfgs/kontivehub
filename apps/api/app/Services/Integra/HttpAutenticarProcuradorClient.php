<?php

namespace App\Services\Integra;

use App\Contracts\AutenticarProcuradorClient;
use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\ProcuradorAuthRequest;
use App\DTO\Serpro\ProcuradorAuthResult;
use App\Enums\SecureObjectPurpose;
use App\Enums\SerproEnvironment;
use App\Enums\TermoAuthorizationState;
use App\Models\OfficeSerproAuthorization;
use App\Services\Serpro\SerproContractService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Throwable;

/**
 * Cliente real Autentica Procurador (ENVIOXMLASSINADO81) via /Apoiar.
 *
 * Envelope: pedidoDados.dados = JSON string {"xml":"<base64>"} — nunca xmlAssinado/XML cru.
 * Token e ETag sensível no vault; Redis só referência opaca + metadados.
 * SERPRO_ACCEPTED somente quando response real (!simulated).
 *
 * Egress via SerproOperationExecutor (executor central).
 */
final class HttpAutenticarProcuradorClient implements AutenticarProcuradorClient
{
    public function __construct(
        private readonly SerproOperationExecutor $operations,
        private readonly SerproContractService $contracts,
        private readonly SecureObjectStore $store,
    ) {}

    public function authenticate(ProcuradorAuthRequest $request): ProcuradorAuthResult
    {
        $this->assertNoLegacyPayload($request);

        $termoHash = hash('sha256', $request->termoXml);
        $environment = SerproEnvironment::tryFrom(strtoupper($request->environment))
            ?? SerproEnvironment::Trial;
        $contract = $this->contracts->activeFor($environment);
        if ($contract === null || ! $contract->isUsable()) {
            return new ProcuradorAuthResult(
                success: false,
                errorCode: 'CONTRACT_UNAVAILABLE',
                errorMessage: 'Contrato SERPRO indisponível.',
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        $contractKey = (string) ($contract->id ?? $contract->contractor_cnpj);
        $cacheKey = $this->cacheKey($request, $termoHash, $contractKey);

        $cachedMeta = Cache::get($cacheKey);
        if (is_array($cachedMeta)
            && ! empty($cachedMeta['vault_object_id'])
            && ! empty($cachedMeta['expires_at'])
            && CarbonImmutable::parse($cachedMeta['expires_at'])->isFuture()
            && ($cachedMeta['termo_sha256'] ?? null) === $termoHash
            && ($cachedMeta['contract_key'] ?? null) === $contractKey
        ) {
            $material = $this->loadVaultMaterial(
                (string) $cachedMeta['vault_object_id'],
                $request,
                $termoHash,
                $contractKey,
            );
            if ($material !== null && ! empty($material['token'])) {
                $state = (string) ($cachedMeta['state'] ?? TermoAuthorizationState::Rejected->value);
                // Nunca promover simulado a SERPRO_ACCEPTED via cache.
                if (($cachedMeta['simulated'] ?? false) && $state === TermoAuthorizationState::SerproAccepted->value) {
                    $state = TermoAuthorizationState::Rejected->value;
                }

                return new ProcuradorAuthResult(
                    success: true,
                    token: (string) $material['token'],
                    expiresAt: CarbonImmutable::parse($cachedMeta['expires_at']),
                    simulated: (bool) ($cachedMeta['simulated'] ?? false),
                    authorizationState: $state,
                    etag: isset($material['etag']) ? (string) $material['etag'] : null,
                );
            }
        }

        $headers = [];
        $cachedEtag = null;
        if (is_array($cachedMeta)
            && ! empty($cachedMeta['vault_object_id'])
            && ! empty($cachedMeta['expires_at'])
            && CarbonImmutable::parse((string) $cachedMeta['expires_at'])->isFuture()) {
            $material = $this->loadVaultMaterial(
                (string) $cachedMeta['vault_object_id'],
                $request,
                $termoHash,
                $contractKey,
            );
            $cachedEtag = is_array($material) ? ($material['etag'] ?? null) : null;
            if (is_string($cachedEtag) && $cachedEtag !== '') {
                $headers['If-None-Match'] = $cachedEtag;
            }
        } elseif (is_array($cachedMeta)) {
            Cache::forget($cacheKey);
            $cachedMeta = null;
        }

        // Envelope oficial: pedidoDados.dados = JSON string {"xml":"<base64>"}.
        $xmlBase64 = base64_encode($request->termoXml);

        // Bloquear se alguém injetou xmlAssinado no business path.
        if (str_contains($request->termoXml, 'xmlAssinado')) {
            return new ProcuradorAuthResult(
                success: false,
                errorCode: 'LEGACY_ENVELOPE_BLOCKED',
                errorMessage: 'Campo xmlAssinado é proibido no envelope Autentica Procurador.',
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        $integraRequest = new IntegraRequest(
            officeId: $request->officeId,
            clientId: 0,
            environment: $request->environment,
            contractorCnpj: (string) $contract->contractor_cnpj,
            authorIdentity: $request->authorIdentity,
            contributorCnpj: $request->authorIdentity,
            operationKey: 'autentica_procurador.envio_xml_assinado',
            // O transporte central serializa businessData uma única vez em
            // pedidoDados.dados. Um wrapper "dados" aqui duplicaria o JSON.
            businessData: ['xml' => $xmlBase64],
            headers: $headers,
            // A renovação não pode reproduzir uma resposta de autenticação
            // expirada de dias anteriores. A janela curta preserva retry
            // idempotente, mas permite pedir um novo token após expiração.
            idempotencyKey: sprintf(
                'procurador-auth-v3:%s:%d',
                $termoHash,
                intdiv(CarbonImmutable::now('America/Sao_Paulo')->getTimestamp(), 300),
            ),
            correlationId: $request->correlationId,
        );

        $response = $this->operations->executeRequest($integraRequest);

        if ($response->httpStatus === 304) {
            if (! is_array($cachedMeta)
                || empty($cachedMeta['vault_object_id'])
                || ($cachedMeta['termo_sha256'] ?? null) !== $termoHash
                || ($cachedMeta['contract_key'] ?? null) !== $contractKey
                || empty($cachedMeta['expires_at'])
                || ! CarbonImmutable::parse((string) $cachedMeta['expires_at'])->isFuture()) {
                Cache::forget($cacheKey);

                $recoveredToken = AutenticaProcuradorEtag::extractToken($response->etag)
                    ?? $this->recoverTokenFromAuthorization($request, $termoHash);
                if ($recoveredToken === null) {
                    return new ProcuradorAuthResult(
                        success: false,
                        errorCode: 'CACHE_INCONSISTENT',
                        errorMessage: '304 sem token íntegro no cofre para o mesmo Termo.',
                        authorizationState: TermoAuthorizationState::Rejected->value,
                    );
                }

                // 304 confirma que o Termo ainda é aceito, mas não traz uma
                // expiração útil. Aplicar o mesmo fallback diário usado para
                // uma resposta sem data de expiração; uma janela de um minuto
                // vence antes de a consulta fiscal subsequente ser iniciada.
                $expires = $this->parseExpiry((string) ($response->expiresHeader ?? ''));
                if ($expires === null || ! $expires->isFuture()) {
                    $expires = CarbonImmutable::now('America/Sao_Paulo')->addDay()->startOfDay()->utc();
                }
                $vaultId = $this->storeTokenMaterial(
                    $request,
                    $termoHash,
                    $contractKey,
                    $recoveredToken,
                    $response->etag,
                );
                Cache::put($cacheKey, [
                    'vault_object_id' => $vaultId,
                    'expires_at' => $expires->toIso8601String(),
                    'simulated' => false,
                    'state' => TermoAuthorizationState::SerproAccepted->value,
                    'termo_sha256' => $termoHash,
                    'contract_key' => $contractKey,
                    'has_etag' => $response->etag !== null && $response->etag !== '',
                ], $expires);

                return new ProcuradorAuthResult(
                    success: true,
                    token: $recoveredToken,
                    expiresAt: $expires,
                    simulated: false,
                    authorizationState: TermoAuthorizationState::SerproAccepted->value,
                    etag: $response->etag,
                );
            }

            $material = $this->loadVaultMaterial(
                (string) $cachedMeta['vault_object_id'],
                $request,
                $termoHash,
                $contractKey,
            );
            if ($material === null || empty($material['token'])) {
                Cache::forget($cacheKey);

                return new ProcuradorAuthResult(
                    success: false,
                    errorCode: 'CACHE_INCONSISTENT',
                    errorMessage: '304 sem material de token íntegro no vault.',
                    authorizationState: TermoAuthorizationState::Rejected->value,
                );
            }

            $state = (string) ($cachedMeta['state'] ?? TermoAuthorizationState::Rejected->value);
            if (($cachedMeta['simulated'] ?? false) === true) {
                $state = TermoAuthorizationState::Rejected->value;
            } elseif (($cachedMeta['simulated'] ?? false) === false && ! $response->simulated) {
                // 304 real reutiliza aceite anterior somente se não era simulado.
                $state = TermoAuthorizationState::SerproAccepted->value;
            }

            return new ProcuradorAuthResult(
                success: true,
                token: (string) $material['token'],
                expiresAt: CarbonImmutable::parse((string) $cachedMeta['expires_at']),
                simulated: (bool) ($cachedMeta['simulated'] ?? false),
                authorizationState: $state,
                etag: $response->etag ?? (isset($material['etag']) ? (string) $material['etag'] : null),
            );
        }

        if (! $response->success) {
            return new ProcuradorAuthResult(
                success: false,
                token: null,
                expiresAt: null,
                errorCode: $response->errorCode ?? 'AUTH_PROCURADOR_FAILED',
                errorMessage: $response->errorMessage ?? 'Falha Autentica Procurador.',
                simulated: $response->simulated,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        if ($response->hasSimulatedSource()) {
            return new ProcuradorAuthResult(
                success: false,
                errorCode: 'SIMULATED_SOURCE_REJECTED',
                errorMessage: 'Resposta sintética não pode criar token do procurador.',
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        $token = $this->extractToken($response->body, $response->dados)
            ?? AutenticaProcuradorEtag::extractToken($response->etag);
        if ($token === null || $token === '') {
            return new ProcuradorAuthResult(
                success: false,
                errorCode: 'TOKEN_MISSING',
                errorMessage: 'Resposta sem token do procurador.',
                simulated: $response->simulated,
                authorizationState: TermoAuthorizationState::Rejected->value,
            );
        }

        $expires = $this->resolveExpiry($response->expiresHeader, $response->body, $response->dados);

        // SERPRO_ACCEPTED somente resposta real (não simulated/fake/fixture).
        $state = TermoAuthorizationState::SerproAccepted->value;

        $vaultId = $this->storeTokenMaterial($request, $termoHash, $contractKey, $token, $response->etag);
        $previousVault = is_array($cachedMeta) ? ($cachedMeta['vault_object_id'] ?? null) : null;

        Cache::put($cacheKey, [
            'vault_object_id' => $vaultId,
            'expires_at' => $expires->toIso8601String(),
            'simulated' => $response->simulated,
            'state' => $state,
            'termo_sha256' => $termoHash,
            'contract_key' => $contractKey,
            'has_etag' => $response->etag !== null && $response->etag !== '',
        ], $expires);

        if (is_string($previousVault) && $previousVault !== '' && $previousVault !== $vaultId) {
            try {
                $this->store->delete($previousVault);
            } catch (Throwable) {
                // não bloquear rotação
            }
        }

        return new ProcuradorAuthResult(
            success: true,
            token: $token,
            expiresAt: $expires,
            simulated: $response->simulated,
            authorizationState: $state,
            etag: $response->etag,
        );
    }

    /**
     * Invalida meta de cache para o contexto (sem precisar do token).
     */
    public function forgetCache(
        int $officeId,
        string $environment,
        string $authorIdentity,
        string $termoSha256,
        string $contractKey,
    ): void {
        $key = sprintf(
            'serpro:procurador:meta:%d:%s:%s:%s:%s',
            $officeId,
            strtoupper($environment),
            substr(hash('sha256', $contractKey), 0, 16),
            substr(hash('sha256', $authorIdentity), 0, 16),
            substr($termoSha256, 0, 16),
        );
        Cache::forget($key);
    }

    /**
     * Monta o envelope de ida e valida o codec Base64 (teste contratual).
     *
     * @return array{dados: string, xml_base64: string}
     */
    public static function buildPedidoDadosEnvelope(string $termoXml): array
    {
        if (str_contains($termoXml, 'xmlAssinado')) {
            throw new InvalidArgumentException('xmlAssinado é proibido.');
        }
        $xmlBase64 = base64_encode($termoXml);
        $dados = json_encode(['xml' => $xmlBase64], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $decoded = json_decode($dados, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded) || ! isset($decoded['xml']) || array_key_exists('xmlAssinado', $decoded)) {
            throw new InvalidArgumentException('Envelope dados inválido.');
        }
        if (base64_decode((string) $decoded['xml'], true) !== $termoXml) {
            throw new InvalidArgumentException('Round-trip Base64 do XML falhou.');
        }

        return ['dados' => $dados, 'xml_base64' => $xmlBase64];
    }

    private function assertNoLegacyPayload(ProcuradorAuthRequest $request): void
    {
        // Defesa em profundidade: o DTO só tem termoXml, mas se XML cru for vazio.
        if (trim($request->termoXml) === '') {
            throw new InvalidArgumentException('Termo XML assinado é obrigatório.');
        }
    }

    private function cacheKey(ProcuradorAuthRequest $request, string $termoHash, string $contractKey): string
    {
        return sprintf(
            'serpro:procurador:meta:%d:%s:%s:%s:%s',
            $request->officeId,
            strtoupper($request->environment),
            substr(hash('sha256', $contractKey), 0, 16),
            substr(hash('sha256', $request->authorIdentity), 0, 16),
            substr($termoHash, 0, 16),
        );
    }

    /**
     * @return array{token?: string, etag?: string|null}|null
     */
    private function loadVaultMaterial(
        string $objectId,
        ProcuradorAuthRequest $request,
        string $termoHash,
        string $contractKey,
    ): ?array {
        try {
            $aad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
                'office_id' => $request->officeId,
                'environment' => $request->environment,
                'author_identity' => $request->authorIdentity,
                'termo_sha256' => $termoHash,
                'contract_key' => $contractKey,
            ]);
            $json = $this->store->get($objectId, $aad);
            /** @var array{token?: string, etag?: string|null} $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function recoverTokenFromAuthorization(ProcuradorAuthRequest $request, string $termoHash): ?string
    {
        try {
            $auth = OfficeSerproAuthorization::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->officeId)
                ->where('environment', strtoupper($request->environment))
                ->where('author_identity', $request->authorIdentity)
                ->first();

            if ($auth?->procurador_token_vault_object_id === null
                || $auth->termo_vault_object_id === null
                || $auth->termo_sha256 === null) {
                return null;
            }

            $termoAad = SecureObjectPurpose::SerproTermoXml->aadBase([
                'office_id' => $request->officeId,
                'environment' => $request->environment,
                'kind' => 'signed',
                'sha256' => $auth->termo_sha256,
                'author_identity' => $request->authorIdentity,
            ]);
            try {
                $storedTermo = $this->store->get($auth->termo_vault_object_id, $termoAad);
            } catch (Throwable) {
                $storedTermo = $this->store->get(
                    $auth->termo_vault_object_id,
                    SecureObjectPurpose::SerproTermoXml->aadBase([
                        'office_id' => $request->officeId,
                        'environment' => $request->environment,
                        'sha256' => $auth->termo_sha256,
                        'author_identity' => $request->authorIdentity,
                    ]),
                );
            }
            if (! hash_equals($termoHash, hash('sha256', $storedTermo))) {
                return null;
            }
            unset($storedTermo);

            $aad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
                'office_id' => $request->officeId,
                'environment' => $request->environment,
                'author_identity' => $request->authorIdentity,
            ]);
            $data = json_decode(
                $this->store->get($auth->procurador_token_vault_object_id, $aad),
                true,
                512,
                JSON_THROW_ON_ERROR,
            );

            return is_array($data) && is_string($data['token'] ?? null) && $data['token'] !== ''
                ? $data['token']
                : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function storeTokenMaterial(
        ProcuradorAuthRequest $request,
        string $termoHash,
        string $contractKey,
        string $token,
        ?string $etag,
    ): string {
        $aad = SecureObjectPurpose::SerproProcuradorToken->aadBase([
            'office_id' => $request->officeId,
            'environment' => $request->environment,
            'author_identity' => $request->authorIdentity,
            'termo_sha256' => $termoHash,
            'contract_key' => $contractKey,
        ]);

        $payload = json_encode([
            'token' => $token,
            'etag' => $etag,
        ], JSON_THROW_ON_ERROR);

        return $this->store->put($payload, $aad);
    }

    /**
     * Prioriza Expires header / data_hora_expiracao; fallback meia-noite seguinte America/Sao_Paulo.
     *
     * @param  array<string, mixed>  $body
     */
    private function resolveExpiry(?string $expiresHeader, array $body, mixed $dados): CarbonImmutable
    {
        if (is_string($expiresHeader) && $expiresHeader !== '') {
            $parsed = $this->parseExpiry($expiresHeader);
            if ($parsed !== null) {
                return $parsed;
            }
        }

        foreach (['data_hora_expiracao', 'expires_at', 'expiracao'] as $k) {
            if (! empty($body[$k]) && is_scalar($body[$k])) {
                $parsed = $this->parseExpiry((string) $body[$k]);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        if (is_array($dados)) {
            foreach (['data_hora_expiracao', 'expires_at'] as $k) {
                if (! empty($dados[$k]) && is_scalar($dados[$k])) {
                    $parsed = $this->parseExpiry((string) $dados[$k]);
                    if ($parsed !== null) {
                        return $parsed;
                    }
                }
            }
        }
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            if (is_array($decoded) && ! empty($decoded['data_hora_expiracao'])) {
                $parsed = $this->parseExpiry((string) $decoded['data_hora_expiracao']);
                if ($parsed !== null) {
                    return $parsed;
                }
            }
        }

        // Meia-noite do dia seguinte em Brasília.
        return CarbonImmutable::now('America/Sao_Paulo')->addDay()->startOfDay()->utc();
    }

    private function parseExpiry(string $value): ?CarbonImmutable
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        try {
            // O retorno do Autentica Procurador pode vir sem offset. O serviço
            // opera em horário de Brasília; assumir UTC tornaria o token válido
            // expirar três horas antes em ambientes UTC.
            if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/', $value)) {
                return CarbonImmutable::parse($value, 'America/Sao_Paulo')->utc();
            }

            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function extractToken(array $body, mixed $dados): ?string
    {
        foreach (['token', 'access_token', 'autenticar_procurador_token'] as $k) {
            if (! empty($body[$k]) && is_scalar($body[$k])) {
                return (string) $body[$k];
            }
        }
        if (is_array($dados)) {
            foreach (['token', 'access_token', 'autenticar_procurador_token'] as $k) {
                if (! empty($dados[$k]) && is_scalar($dados[$k])) {
                    return (string) $dados[$k];
                }
            }
        }
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            if (is_array($decoded)) {
                foreach (['token', 'access_token', 'autenticar_procurador_token'] as $k) {
                    if (! empty($decoded[$k])) {
                        return (string) $decoded[$k];
                    }
                }
            }
        }

        return null;
    }
}
