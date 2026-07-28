<?php

namespace App\DTO\Serpro;

final readonly class CredentialVersionActivationData
{
    public function __construct(
        public bool $skipOauth,
        public ?string $reason,
        public ?int $approvalId,
        public ?int $contractId,
    ) {}
}
