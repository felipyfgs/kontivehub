<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Enums\MonitoringQueryState;

final readonly class MonitoringQueryProjectionDto
{
    public function __construct(
        public MonitoringQueryState $state,
        public ?string $observedAt,
        public string $sourceProvenance,
        public string $coverage,
        public ?string $reasonCode,
        public ?int $runId,
        public MonitoringFreshnessDto $freshness,
        public ?MonitoringSnapshotReferenceDto $lastSnapshot,
        public bool $hasPreservedSnapshot,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'state' => $this->state->value,
            // Alias transitório: o valor já usa exclusivamente o estado comum.
            'status' => $this->state->value,
            'state_label' => $this->state->label(),
            'observed_at' => $this->observedAt,
            'source_provenance' => $this->sourceProvenance,
            'coverage' => $this->coverage,
            'reason_code' => $this->reasonCode,
            'run_id' => $this->runId,
            'freshness' => $this->freshness->toArray(),
            'last_snapshot' => $this->lastSnapshot?->toArray(),
            'has_preserved_snapshot' => $this->hasPreservedSnapshot,
        ];
    }
}
