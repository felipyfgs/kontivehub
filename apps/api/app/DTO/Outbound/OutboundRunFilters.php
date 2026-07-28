<?php

namespace App\DTO\Outbound;

final readonly class OutboundRunFilters
{
    public function __construct(
        public ?int $seriesCursorId,
    ) {}
}
