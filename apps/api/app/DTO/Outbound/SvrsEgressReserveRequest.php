<?php

namespace App\DTO\Outbound;

/**
 * Pedido de reserva de orçamento (antes de materializar A1 ou rede).
 */
final class SvrsEgressReserveRequest
{
    public function __construct(
        public readonly string $rootCnpj,
        public readonly string $accessKeyMask,
        public readonly string $channel,
        public readonly int $officeId,
        public readonly int $exchangesNeeded = 2,
        public readonly bool $isCanary = false,
        public readonly ?string $correlationId = null,
    ) {
        if ($this->exchangesNeeded < 1 || $this->exchangesNeeded > 4) {
            throw new \InvalidArgumentException('exchangesNeeded inválido.');
        }
        if (! in_array($this->channel, ['nfe55', 'nfce65'], true)) {
            throw new \InvalidArgumentException('channel deve ser nfe55|nfce65.');
        }
    }
}
