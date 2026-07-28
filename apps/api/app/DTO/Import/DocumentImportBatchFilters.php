<?php

namespace App\DTO\Import;

final readonly class DocumentImportBatchFilters
{
    public function __construct(
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}
}
