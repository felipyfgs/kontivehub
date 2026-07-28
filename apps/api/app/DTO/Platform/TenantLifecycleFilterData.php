<?php

namespace App\DTO\Platform;

use App\Enums\TenantLifecycleStatus;

final readonly class TenantLifecycleFilterData
{
    public function __construct(
        public ?TenantLifecycleStatus $status,
    ) {}
}
