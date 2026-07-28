<?php

namespace App\DTO\Work;

final readonly class WorkDepartmentFiltersData
{
    public function __construct(
        public ?bool $isActive,
        public int $perPage,
        public int $page,
    ) {}
}
