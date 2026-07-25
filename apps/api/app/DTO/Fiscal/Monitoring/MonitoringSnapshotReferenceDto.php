<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class MonitoringSnapshotReferenceDto
{
    public function __construct(
        public int $snapshotId,
        public ?string $observedAt,
        public string $sourceProvenance,
        public string $coverage,
        public MonitoringFreshnessDto $freshness,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'snapshot_id' => $this->snapshotId,
            'observed_at' => $this->observedAt,
            'source_provenance' => $this->sourceProvenance,
            'coverage' => $this->coverage,
            'freshness' => $this->freshness->toArray(),
        ];
    }
}
