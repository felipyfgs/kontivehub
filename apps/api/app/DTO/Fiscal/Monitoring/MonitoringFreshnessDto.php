<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Enums\MonitoringFreshnessState;

final readonly class MonitoringFreshnessDto
{
    public function __construct(
        public MonitoringFreshnessState $state,
        public ?int $ageSeconds,
        public int $ttlSeconds,
    ) {}

    /** @return array{state: string, age_seconds: int|null, ttl_seconds: int} */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            'age_seconds' => $this->ageSeconds,
            'ttl_seconds' => $this->ttlSeconds,
        ];
    }
}
