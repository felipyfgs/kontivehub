<?php

namespace App\DTO\Clients;

use App\Models\Establishment;

final readonly class EstablishmentUpdateResult
{
    /** @param array<string, mixed> $captureEligibility */
    public function __construct(
        public Establishment $establishment,
        public array $captureEligibility,
    ) {}
}
