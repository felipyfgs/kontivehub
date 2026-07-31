<?php

namespace App\DTO\Work;

final readonly class GenerationBatchFiltersData
{
    public function __construct(
        public ?string $status,
        public ?string $competence,
        public int $perPage,
        public int $page,
    ) {}
}
