<?php

namespace App\Http\Resources;

use App\DTO\Tenant\TenantProxyPowerSyncResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantProxyPowerSyncResult */
final class TenantProxyPowerSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantProxyPowerSyncResult $result */
        $result = $this->resource;

        return TaxProxyPowerResource::collection(
            collect($result->powers),
        )->resolve($request);
    }

    public function with(Request $request): array
    {
        /** @var TenantProxyPowerSyncResult $result */
        $result = $this->resource;

        return [
            'procuracao' => ClientProcuracaoSyncResource::make(
                $result->sync,
            )->resolve($request),
        ];
    }
}
