<?php

namespace App\DTO\Serpro;

final readonly class DteCanaryDisableData
{
    public function __construct(
        public string $confirmationPhrase,
        public string $reason,
    ) {}
}
