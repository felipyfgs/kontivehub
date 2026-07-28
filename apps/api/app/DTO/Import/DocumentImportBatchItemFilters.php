<?php

namespace App\DTO\Import;

final readonly class DocumentImportBatchItemFilters
{
    public function __construct(
        public ?string $status,
        public string $sort,
        public string $direction,
        public int $perPage,
    ) {}
}
