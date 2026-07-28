<?php

namespace App\DTO\Serpro;

final readonly class SerproCircuitBreakerResetData
{
    public function __construct(
        public string $reason,
    ) {}
}
