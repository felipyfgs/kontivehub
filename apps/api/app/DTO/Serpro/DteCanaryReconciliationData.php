<?php

namespace App\DTO\Serpro;

final readonly class DteCanaryReconciliationData
{
    public function __construct(
        public string $reference,
        public string $summary,
    ) {}
}
