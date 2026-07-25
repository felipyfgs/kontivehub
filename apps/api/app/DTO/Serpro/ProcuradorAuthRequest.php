<?php

namespace App\DTO\Serpro;

final class ProcuradorAuthRequest
{
    public function __construct(
        public readonly int $officeId,
        public readonly string $environment,
        public readonly string $authorIdentity,
        public readonly string $termoXml,
        public readonly string $contractorBearerToken,
        public readonly ?string $correlationId = null,
    ) {}
}
