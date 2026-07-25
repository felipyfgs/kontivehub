<?php

namespace App\Services\Fiscal\Guides\DTO;

/**
 * Resultado de emissão bem-sucedida na fonte.
 */
final class GuideEmissionResult
{
    public function __construct(
        public readonly string $documentBytes,
        public readonly string $contentType,
        public readonly string $identifierCode,
        public readonly int $amountCents,
        public readonly ?string $dueAtIso,
        public readonly ?string $validUntilIso,
        public readonly ?string $remoteProtocol,
        public readonly ?string $correlationId = null,
        public readonly int $latencyMs = 0,
        public readonly bool $simulated = false,
    ) {}
}
