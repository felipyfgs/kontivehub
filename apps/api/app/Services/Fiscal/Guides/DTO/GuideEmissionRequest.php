<?php

namespace App\Services\Fiscal\Guides\DTO;

/**
 * Pedido de emissão para a fonte oficial (sem credenciais).
 */
final class GuideEmissionRequest
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly string $systemCode,
        public readonly string $serviceCode,
        public readonly string $operationCode,
        public readonly ?string $competencePeriodKey,
        public readonly ?string $debitRef,
        public readonly ?int $amountCents,
        public readonly ?string $dueAtIso,
        public readonly string $idempotencyKey,
        public readonly ?string $correlationId,
        public readonly array $payload = [],
    ) {}
}
