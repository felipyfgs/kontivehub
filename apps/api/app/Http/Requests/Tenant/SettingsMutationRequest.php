<?php

namespace App\Http\Requests\Tenant;

abstract class SettingsMutationRequest extends SettingsRequest
{
    protected function requiresManagementPermission(): bool
    {
        return true;
    }
}
