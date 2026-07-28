<?php

namespace App\DTO\Clients;

final readonly class ClientCategoryReplacementData
{
    /** @param list<int> $categoryIds */
    public function __construct(
        public array $categoryIds,
        public int $actorId,
    ) {}
}
