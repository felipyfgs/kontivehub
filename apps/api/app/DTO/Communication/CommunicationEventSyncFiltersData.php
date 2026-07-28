<?php

namespace App\DTO\Communication;

final readonly class CommunicationEventSyncFiltersData
{
    public function __construct(
        public int $after,
        public int $limit,
    ) {}
}
