<?php

namespace App\DTO\Work;

final readonly class WorkTaskReorderData
{
    /** @param list<array{id: int, sort_order: int, lock_version: int}> $order */
    public function __construct(
        public array $order,
        public ?string $justification,
    ) {}
}
