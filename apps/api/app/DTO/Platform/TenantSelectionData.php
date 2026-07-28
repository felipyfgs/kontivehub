<?php

namespace App\DTO\Platform;

final readonly class TenantSelectionData
{
    public function __construct(
        public int $tenantId,
    ) {}
}
