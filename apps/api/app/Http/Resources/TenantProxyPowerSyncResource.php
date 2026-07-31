<?php

namespace App\Http\Resources;

use App\DTO\Tenant\ProxyPowerSyncResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ProxyPowerSyncResult */
final class TenantProxyPowerSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ProxyPowerSyncResult $result */
        $result = $this->resource;

        return TaxProxyPowerResource::collection(
            collect($result->powers),
        )->resolve($request);
    }

    public function with(Request $request): array
    {
        /** @var ProxyPowerSyncResult $result */
        $result = $this->resource;

        return [
            'procuracao' => ClientProcuracaoSyncResource::make(
                $result->sync,
            )->resolve($request),
        ];
    }
}
