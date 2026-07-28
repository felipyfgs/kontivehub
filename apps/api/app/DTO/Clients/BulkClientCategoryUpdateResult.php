<?php

namespace App\DTO\Clients;

final readonly class BulkClientCategoryUpdateResult
{
    /**
     * @param  list<int>  $clientIds
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public string $operation,
        public array $clientIds,
        public array $categoryIds,
        public int $createdLinks,
        public int $removedLinks,
    ) {}
}
