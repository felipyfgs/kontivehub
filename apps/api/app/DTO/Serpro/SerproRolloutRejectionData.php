<?php

namespace App\DTO\Serpro;

final readonly class SerproRolloutRejectionData
{
    public function __construct(
        public string $reason,
    ) {}
}
