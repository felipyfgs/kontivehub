<?php

namespace App\DTO\Work;

final readonly class WorkProcessFiltersData
{
    /** @param array<string, mixed> $filters */
    public function __construct(
        public array $filters,
        public bool $includeTasks,
    ) {}
}
