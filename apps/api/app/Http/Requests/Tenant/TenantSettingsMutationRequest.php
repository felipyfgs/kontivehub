<?php

namespace App\Http\Requests\Tenant;

abstract class TenantSettingsMutationRequest extends TenantSettingsRequest
{
    protected function requiresManagementPermission(): bool
    {
        return true;
    }
}
