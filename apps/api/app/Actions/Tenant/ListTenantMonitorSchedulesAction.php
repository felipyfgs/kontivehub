<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\TenantMonitorScheduleData;
use App\Models\TenantMonitorSchedulePolicy;
use App\Services\Tenant\TenantMonitorScheduleCatalog;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

final readonly class ListTenantMonitorSchedulesAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantMonitorScheduleCatalog $catalog,
    ) {}

    /** @return Collection<int, TenantMonitorScheduleData> */
    public function __invoke(): Collection
    {
        $tenantId = $this->currentTenant->id();

        return collect($this->catalog->all())
            ->map(fn (string $label, string $monitorKey): TenantMonitorScheduleData => new TenantMonitorScheduleData(
                policy: TenantMonitorSchedulePolicy::ensureDefault($tenantId, $monitorKey),
                label: $label,
            ))
            ->values();
    }
}
