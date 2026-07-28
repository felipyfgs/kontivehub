<?php

namespace App\DTO\FgtsDigital;

final readonly class FgtsDigitalRunFilters
{
    public function __construct(
        public ?int $clientId,
        public int $perPage,
    ) {}
}
