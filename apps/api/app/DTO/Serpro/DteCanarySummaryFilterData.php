<?php

namespace App\DTO\Serpro;

final readonly class DteCanarySummaryFilterData
{
    public function __construct(
        public ?int $requestId,
    ) {}
}
