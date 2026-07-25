<?php

namespace App\DTO\Serpro;

final class ProcuracaoLookupRequest
{
    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly string $environment,
        public readonly string $authorIdentity,
        public readonly string $contributorCnpj,
        public readonly ?string $powerCode = null,
        public readonly ?string $correlationId = null,
    ) {}
}
