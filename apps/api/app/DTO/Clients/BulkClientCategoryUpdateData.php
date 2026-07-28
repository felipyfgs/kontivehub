<?php

namespace App\DTO\Clients;

use App\Models\User;

final readonly class BulkClientCategoryUpdateData
{
    /**
     * @param  list<int>  $clientIds
     * @param  list<int>  $categoryIds
     */
    public function __construct(
        public string $operation,
        public array $clientIds,
        public array $categoryIds,
        public User $actor,
    ) {}
}
