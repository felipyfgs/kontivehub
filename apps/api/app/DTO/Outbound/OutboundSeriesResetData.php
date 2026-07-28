<?php

namespace App\DTO\Outbound;

final readonly class OutboundSeriesResetData
{
    public function __construct(
        public string $reason,
        public int $discoveryPosition,
    ) {}
}
