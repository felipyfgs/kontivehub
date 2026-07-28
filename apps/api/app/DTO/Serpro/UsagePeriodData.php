<?php

namespace App\DTO\Serpro;

final readonly class UsagePeriodData
{
    public function __construct(
        public ?int $year,
        public ?int $month,
    ) {}
}
