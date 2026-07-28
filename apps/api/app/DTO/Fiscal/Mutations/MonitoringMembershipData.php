<?php

namespace App\DTO\Fiscal\Mutations;

use App\Enums\FiscalModuleKey;

final readonly class MonitoringMembershipData
{
    /**
     * @param  list<int>  $clientIds
     */
    public function __construct(
        public ?FiscalModuleKey $module,
        public ?string $submodule,
        public array $clientIds,
    ) {}

    public function isValidModule(): bool
    {
        return $this->module !== null
            && $this->module !== FiscalModuleKey::Dashboard;
    }
}
