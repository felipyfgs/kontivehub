<?php

namespace App\Services\Serpro\Usage;

use App\Models\SerproApiUsageReservation;

/**
 * Resultado da reserva (idempotente).
 */
final class UsageReserveOutcome
{
    /**
     * @param  array<string, mixed>  $budget
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly SerproApiUsageReservation $reservation,
        public readonly bool $replayed,
        public readonly array $budget = [],
    ) {}
}
