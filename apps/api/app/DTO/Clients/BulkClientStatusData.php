<?php

namespace App\DTO\Clients;

final readonly class BulkClientStatusData
{
    /** @param list<int> $clientIds */
    public function __construct(
        public array $clientIds,
        public bool $isActive,
        public ?string $inactiveReason,
    ) {}
}
