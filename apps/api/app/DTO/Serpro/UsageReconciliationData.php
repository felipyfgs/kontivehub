<?php

namespace App\DTO\Serpro;

final readonly class UsageReconciliationData
{
    /**
     * @param  list<UsageReconciliationAdjustmentData>  $adjustments
     */
    public function __construct(
        public int $year,
        public int $month,
        public int $officialTotalCostMicros,
        public ?string $officialReference,
        public ?string $officialSource,
        public ?string $notes,
        public ?string $differenceCause,
        public bool $recomputeAggregates,
        public array $adjustments,
    ) {}

    /**
     * @return list<array{
     *     tenant_id: int|null,
     *     service_code: string|null,
     *     consumption_class: string|null,
     *     amount_micros: int,
     *     reason: string,
     *     notes: string|null
     * }>
     */
    public function adjustmentPayloads(): array
    {
        return array_map(
            static fn (UsageReconciliationAdjustmentData $adjustment): array => $adjustment->toArray(),
            $this->adjustments,
        );
    }
}
