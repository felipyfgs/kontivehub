<?php

namespace App\DTO\FgtsDigital;

use App\Models\FgtsDigitalSession;

final readonly class FgtsDigitalSessionData
{
    public function __construct(
        public int $id,
        public int $tenantId,
        public int $clientId,
        public string $credentialSource,
        public string $profileType,
        public string $status,
        public ?string $expiresAt,
        public ?string $lastUsedAt,
        public ?string $createdAt,
    ) {}

    public static function fromModel(FgtsDigitalSession $session): self
    {
        return new self(
            id: (int) $session->id,
            tenantId: (int) $session->tenant_id,
            clientId: (int) $session->client_id,
            credentialSource: $session->credential_source->value,
            profileType: (string) $session->profile_type,
            status: $session->status->value,
            expiresAt: $session->expires_at?->toIso8601String(),
            lastUsedAt: $session->last_used_at?->toIso8601String(),
            createdAt: $session->created_at?->toIso8601String(),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenantId,
            'client_id' => $this->clientId,
            'credential_source' => $this->credentialSource,
            'profile_type' => $this->profileType,
            'status' => $this->status,
            'expires_at' => $this->expiresAt,
            'last_used_at' => $this->lastUsedAt,
            'created_at' => $this->createdAt,
        ];
    }
}
