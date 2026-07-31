<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\ProxyPowerListFilterData;
use App\Models\TaxProxyPower;
use App\Support\CurrentTenant;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListTenantProxyPowersAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
    ) {}

    /** @return LengthAwarePaginator<int, TaxProxyPower> */
    public function __invoke(
        ProxyPowerListFilterData $filters,
    ): LengthAwarePaginator {
        $query = TaxProxyPower::query()
            ->where('tenant_id', $this->currentTenant->id());

        if ($filters->clientId !== null) {
            $query->where('client_id', $filters->clientId);
        }

        $query->orderBy($filters->sort, $filters->direction);
        if ($filters->sort !== 'id') {
            $query->orderBy('id', $filters->direction);
        }

        return $query->paginate($filters->perPage);
    }
}
