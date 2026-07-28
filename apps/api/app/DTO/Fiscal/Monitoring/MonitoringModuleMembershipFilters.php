<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Enums\FiscalModuleKey;

final readonly class MonitoringModuleMembershipFilters
{
    public function __construct(
        public ?FiscalModuleKey $module,
        public ?string $submodule,
    ) {}
}
