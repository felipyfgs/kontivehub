<?php

namespace App\DTO\Clients;

final readonly class BulkClientStatusResult
{
    /** @param list<int> $clientIds */
    public function __construct(
        public int $updated,
        public array $clientIds,
        public bool $isActive,
    ) {}
}
