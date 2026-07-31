<?php

namespace App\DTO\Communication;

final readonly class EventSyncFiltersData
{
    public function __construct(
        public int $after,
        public int $limit,
    ) {}
}
