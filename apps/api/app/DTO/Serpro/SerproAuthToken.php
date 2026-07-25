<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * Par autenticado do contratante (access_token + jwt_token oficiais).
 * Valores sensíveis — não serializar em log/API.
 */
final class SerproAuthToken
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $tokenType,
        public readonly CarbonImmutable $expiresAt,
        public readonly ?string $jwtToken = null,
        public readonly bool $fromCache = false,
        /** @deprecated Use jwtToken — alias legado do campo oficial jwt_token */
        public readonly ?string $jwt = null,
    ) {
        // Preferir jwtToken; aceitar jwt legado no cache
        if ($this->jwtToken === null && $this->jwt !== null) {
            // readonly: normalizado via accessor
        }
    }

    public function officialJwt(): ?string
    {
        $value = $this->jwtToken ?? $this->jwt;

        return $value !== null && $value !== '' ? $value : null;
    }

    public function requiresJwt(): bool
    {
        return true;
    }

    public function assertComplete(): void
    {
        if ($this->accessToken === '') {
            throw new RuntimeException('access_token ausente no contexto autenticado.');
        }
        if ($this->officialJwt() === null) {
            throw new RuntimeException('jwt_token ausente no contexto autenticado.');
        }
    }

    public function isExpired(int $skewSeconds = 0): bool
    {
        return $this->expiresAt->lessThanOrEqualTo(now()->addSeconds($skewSeconds));
    }

    /**
     * @return array{token_type: string, expires_at: string, from_cache: bool, has_access_token: bool, has_jwt_token: bool}
     */
    public function toSanitizedArray(): array
    {
        return [
            'token_type' => $this->tokenType,
            'expires_at' => $this->expiresAt->toIso8601String(),
            'from_cache' => $this->fromCache,
            'has_access_token' => $this->accessToken !== '',
            'has_jwt_token' => $this->officialJwt() !== null,
            // legado
            'has_jwt' => $this->officialJwt() !== null,
        ];
    }
}
