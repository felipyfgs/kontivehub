<?php

namespace App\DTO\Communication;

use App\Models\CommunicationEvent;
use Illuminate\Support\Collection;

final readonly class EventPageData
{
    /** @param Collection<int, CommunicationEvent> $events */
    public function __construct(
        public Collection $events,
        public int $nextCursor,
        public bool $hasMore,
    ) {}
}
