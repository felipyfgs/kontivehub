<?php

namespace App\DTO\Serpro;

use Carbon\CarbonImmutable;

final class ProcuradorAuthResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $token = null,
        public readonly ?CarbonImmutable $expiresAt = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly bool $simulated = false,
        public readonly bool $requiresNewSignature = false,
        public readonly ?string $authorizationState = null,
        public readonly ?string $etag = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'success' => $this->success,
            'has_token' => $this->token !== null && $this->token !== '',
            'expires_at' => $this->expiresAt?->toIso8601String(),
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
            'simulated' => $this->simulated,
            'requires_new_signature' => $this->requiresNewSignature,
            'authorization_state' => $this->authorizationState,
            'has_etag' => $this->etag !== null && $this->etag !== '',
        ];
    }
}
