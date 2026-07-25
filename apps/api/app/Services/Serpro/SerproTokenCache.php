<?php

namespace App\Services\Serpro;

use App\Contracts\SecureObjectStore;
use App\DTO\Serpro\SerproAuthToken;
use App\Enums\SecureObjectPurpose;
use App\Models\SerproContract;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use Throwable;

/**
 * Cache cifrado de Bearer/JWT no SecureObjectStore + lock anti-stampede.
 */
final class SerproTokenCache
{
    public function __construct(
        private readonly SecureObjectStore $store,
    ) {}

    public function get(SerproContract $contract): ?SerproAuthToken
    {
        if ($contract->token_vault_object_id === null || $contract->token_expires_at === null) {
            return null;
        }

        $skew = (int) config('serpro.oauth.expiry_skew_seconds', 120);
        if ($contract->token_expires_at->lessThanOrEqualTo(now()->addSeconds($skew))) {
            return null;
        }

        try {
            $aad = $this->aad($contract);
            $json = $this->store->get($contract->token_vault_object_id, $aad);
            /** @var array{access_token: string, token_type: string, expires_at: string, jwt?: string|null} $data */
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

            $expiresAt = CarbonImmutable::parse((string) $data['expires_at']);
            if ($expiresAt->lessThanOrEqualTo(now()->addSeconds($skew))) {
                return null;
            }

            $jwt = null;
            if (! empty($data['jwt_token'])) {
                $jwt = (string) $data['jwt_token'];
            } elseif (! empty($data['jwt'])) {
                $jwt = (string) $data['jwt'];
            }

            return new SerproAuthToken(
                accessToken: (string) $data['access_token'],
                tokenType: (string) ($data['token_type'] ?? 'Bearer'),
                expiresAt: $expiresAt,
                jwtToken: $jwt,
                fromCache: true,
                jwt: $jwt,
            );
        } catch (Throwable) {
            return null;
        }
    }

    public function put(SerproContract $contract, SerproAuthToken $token): void
    {
        $aad = $this->aad($contract);
        $jwt = $token->officialJwt();
        $payload = json_encode([
            'access_token' => $token->accessToken,
            'token_type' => $token->tokenType,
            'expires_at' => $token->expiresAt->toIso8601String(),
            'jwt_token' => $jwt,
            'jwt' => $jwt, // legado de leitura
        ], JSON_THROW_ON_ERROR);

        $previous = $contract->token_vault_object_id;
        $objectId = $this->store->put($payload, $aad);

        $contract->token_vault_object_id = $objectId;
        $contract->token_expires_at = $token->expiresAt;
        $contract->last_auth_at = now();
        $contract->save();

        if ($previous !== null && $previous !== $objectId) {
            try {
                $this->store->delete($previous);
            } catch (Throwable) {
                // não bloquear rotação
            }
        }
    }

    public function invalidate(SerproContract $contract): void
    {
        $objectId = $contract->token_vault_object_id;
        $contract->token_vault_object_id = null;
        $contract->token_expires_at = null;
        $contract->save();

        if ($objectId !== null) {
            try {
                $this->store->delete($objectId);
            } catch (Throwable) {
                // ignore
            }
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function withRefreshLock(SerproContract $contract, callable $callback): mixed
    {
        $lockSeconds = (int) config('serpro.oauth.lock_seconds', 30);
        $waitSeconds = (int) config('serpro.oauth.lock_wait_seconds', 20);
        $lock = Cache::lock('serpro.oauth.refresh.'.$contract->id, $lockSeconds);

        try {
            $lock->block($waitSeconds);

            return $callback();
        } catch (LockTimeoutException $e) {
            throw new RuntimeException('Timeout ao renovar token SERPRO (stampede protection).', 0, $e);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array<string, scalar|null>
     */
    private function aad(SerproContract $contract): array
    {
        return SecureObjectPurpose::SerproBearerToken->aadBase([
            'contract_id' => $contract->id,
            'environment' => $contract->environment->value,
            'contractor_cnpj' => $contract->contractor_cnpj,
        ]);
    }
}
