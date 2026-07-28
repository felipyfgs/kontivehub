<?php

namespace App\Http\Requests\Tenant;

final class RejectManualTenantProxyPowerRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
