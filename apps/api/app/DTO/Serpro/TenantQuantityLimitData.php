<?php

namespace App\DTO\Serpro;

final readonly class TenantQuantityLimitData
{
    public function __construct(
        public int $tenantId,
        public ?int $limitQuantity,
    ) {}

    /** @return array{tenant_id: int, limit_quantity: int|null} */
    public function toArray(): array
    {
        return [
            'tenant_id' => $this->tenantId,
            'limit_quantity' => $this->limitQuantity,
        ];
    }
}
