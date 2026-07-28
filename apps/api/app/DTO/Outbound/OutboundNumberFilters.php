<?php

namespace App\DTO\Outbound;

final readonly class OutboundNumberFilters
{
    public function __construct(
        public bool $gapsOnly,
    ) {}
}
