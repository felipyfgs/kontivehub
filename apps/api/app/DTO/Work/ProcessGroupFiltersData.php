<?php

namespace App\DTO\Work;

final readonly class ProcessGroupFiltersData
{
    /** @param array<string, mixed> $filters */
    public function __construct(public array $filters) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return $this->filters;
    }
}
