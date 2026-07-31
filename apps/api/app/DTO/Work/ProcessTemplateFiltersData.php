<?php

namespace App\DTO\Work;

final readonly class ProcessTemplateFiltersData
{
    public function __construct(
        public ?bool $isActive,
        public ?string $search,
        public string $sort,
        public string $direction,
        public int $perPage,
        public int $page,
    ) {}
}
