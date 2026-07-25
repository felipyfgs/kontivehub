<?php

namespace App\DTO\Outbound;

/**
 * Reserva atômica de exchanges — opaca para adapters.
 */
final class SvrsEgressReservation
{
    public function __construct(
        public readonly string $id,
        public readonly string $cohortId,
        public readonly string $rootCnpj,
        public readonly string $channel,
        public readonly int $officeId,
        public readonly int $exchangesReserved,
        public int $exchangesConsumed = 0,
    ) {}
}
