<?php

namespace App\Actions\Platform;

use App\DTO\Platform\TenantLifecycleFilterData;
use App\Enums\TenantLifecycleStatus;
use App\Models\Tenant;
use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListPlatformTenantsAction
{
    public function __construct(
        private DemoEnvironmentGuard $demoEnvironment,
    ) {}

    /** @return Collection<int, Tenant> */
    public function __invoke(TenantLifecycleFilterData $filter): Collection
    {
        $query = Tenant::query()
            ->with(['subscription', 'latestFirstAdminActivation'])
            ->orderByDesc('id');

        if ($filter->status instanceof TenantLifecycleStatus) {
            $query->where('lifecycle_status', $filter->status->value);
        } else {
            $query->where(fn ($visible) => $visible
                ->where('is_active', true)
                ->orWhere('lifecycle_status', TenantLifecycleStatus::PendingActivation->value));
        }

        if ($this->demoEnvironment->isAllowedEnvironment()) {
            $sentinelSlug = trim($this->demoEnvironment->sentinelTenantSlug());
            if ($sentinelSlug !== '') {
                $query->where('slug', '!=', $sentinelSlug);
            }
        }

        return $query->get();
    }
}
