<?php

namespace App\Services\Serpro\Usage;

/**
 * Pedido de reserva de orçamento antes da chamada HTTP SERPRO.
 * Sem payload fiscal.
 */
final class UsageReserveRequest
{
    public function __construct(
        public readonly int $officeId,
        public readonly string $idempotencyKey,
        public readonly string $systemCode,
        public readonly string $serviceCode,
        public readonly string $operationCode,
        public readonly int $quantity = 1,
        public readonly ?int $clientId = null,
        public readonly ?string $contributorRef = null,
        public readonly ?string $correlationId = null,
        public readonly ?bool $forceEssential = null,
        public readonly ?string $operationKey = null,
        public readonly bool $isSimulated = false,
        public readonly ?string $functionalRoute = null,
        public readonly ?string $requestTag = null,
        public readonly ?string $environment = null,
        public readonly ?int $serproContractId = null,
        public readonly bool $isCanary = false,
        public readonly ?string $catalogRevision = null,
    ) {
        if ($this->officeId <= 0) {
            throw new \InvalidArgumentException('office_id obrigatório para reserva de uso SERPRO.');
        }
        if ($this->idempotencyKey === '') {
            throw new \InvalidArgumentException('idempotency_key obrigatório.');
        }
        if ($this->quantity < 1) {
            throw new \InvalidArgumentException('quantity deve ser >= 1.');
        }
        if ($this->requestTag !== null && strlen($this->requestTag) > 32) {
            throw new \InvalidArgumentException('request_tag deve ter no máximo 32 caracteres.');
        }
    }
}
