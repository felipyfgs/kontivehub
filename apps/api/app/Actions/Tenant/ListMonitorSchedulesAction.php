<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\MonitorScheduleData;
use App\Models\TenantMonitorSchedulePolicy;
use App\Services\Tenant\TenantMonitorScheduleCatalog;
use App\Support\CurrentTenant;
use Illuminate\Support\Collection;

final readonly class ListMonitorSchedulesAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantMonitorScheduleCatalog $catalog,
    ) {}

    /** @return Collection<int, MonitorScheduleData> */
    public function __invoke(): Collection
    {
        $tenantId = $this->currentTenant->id();

        return collect($this->catalog->all())
            ->map(fn (string $label, string $monitorKey): MonitorScheduleData => new MonitorScheduleData(
                policy: TenantMonitorSchedulePolicy::ensureDefault($tenantId, $monitorKey),
                label: $label,
            ))
            ->values();
    }
}
