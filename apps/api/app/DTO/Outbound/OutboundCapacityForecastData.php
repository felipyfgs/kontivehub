<?php

namespace App\DTO\Outbound;

use App\Models\OutboundCapacitySnapshot;

final readonly class OutboundCapacityForecastData
{
    /** @param array<string, mixed> $projection */
    public function __construct(
        public string $competence,
        public array $projection,
        public ?OutboundCapacitySnapshot $latestSnapshot,
    ) {}
}
