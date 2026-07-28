<?php

namespace App\DTO\Serpro;

final readonly class UsageRecomputeData
{
    public function __construct(
        public int $year,
        public int $month,
        public ?int $tenantId,
    ) {}
}
