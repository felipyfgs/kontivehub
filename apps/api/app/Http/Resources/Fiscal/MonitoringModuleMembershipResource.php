<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TenantMonitoringModuleExclusion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TenantMonitoringModuleExclusion */
final class MonitoringModuleMembershipResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TenantMonitoringModuleExclusion $exclusion */
        $exclusion = $this->resource;

        return [
            'id' => $exclusion->id,
            'tenant_id' => $exclusion->tenant_id,
            'client_id' => $exclusion->client_id,
            'module_key' => $exclusion->module_key,
            'submodule' => $exclusion->submodule,
            'excluded_by' => $exclusion->excluded_by,
            'created_at' => $exclusion->created_at?->toIso8601String(),
        ];
    }
}
