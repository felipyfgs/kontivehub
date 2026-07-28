<?php

namespace App\DTO\Serpro;

final readonly class UsageReconciliationAdjustmentData
{
    public function __construct(
        public ?int $tenantId,
        public ?string $serviceCode,
        public ?string $consumptionClass,
        public int $amountMicros,
        public string $reason,
        public ?string $notes,
    ) {}

    /**
     * @return array{
     *     tenant_id: int|null,
     *     service_code: string|null,
     *     consumption_class: string|null,
     *     amount_micros: int,
     *     reason: string,
     *     notes: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'service_code' => $this->serviceCode,
            'consumption_class' => $this->consumptionClass,
            'amount_micros' => $this->amountMicros,
            'reason' => $this->reason,
            'notes' => $this->notes,
        ];
    }
}
