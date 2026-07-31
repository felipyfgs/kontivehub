<?php

namespace App\DTO\Work;

final readonly class ExportFiltersData
{
    /** @param array<string, int|string|null> $filters */
    public function __construct(public array $filters) {}

    /** @return array<string, int|string|null> */
    public function toArray(): array
    {
        return $this->filters;
    }
}
