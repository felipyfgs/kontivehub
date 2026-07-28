<?php

namespace App\DTO\Work;

final readonly class WorkTaskBulkData
{
    /**
     * @param  list<array{id: int, lock_version: int}>  $items
     * @param  array<string, mixed>  $changes
     */
    public function __construct(
        public array $items,
        public array $changes,
    ) {}
}
