<?php

namespace App\DTO\Outbound;

/**
 * Resultado tipado da reserva (sem detalhes de IP/chave completa).
 */
final class SvrsEgressReserveResult
{
    public function __construct(
        public readonly bool $allowed,
        public readonly ?SvrsEgressReservation $reservation = null,
        public readonly int $retryAfterSeconds = 0,
        public readonly ?string $reason = null,
    ) {}

    public static function deny(string $reason, int $retryAfterSeconds = 0): self
    {
        return new self(false, null, max(0, $retryAfterSeconds), $reason);
    }

    public static function allow(SvrsEgressReservation $reservation): self
    {
        return new self(true, $reservation, 0, null);
    }
}
