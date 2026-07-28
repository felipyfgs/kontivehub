<?php

namespace App\Http\Requests\Tenant;

final class RefreshTenantIntegrationRequest extends TenantSettingsMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
