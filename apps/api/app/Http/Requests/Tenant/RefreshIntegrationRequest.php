<?php

namespace App\Http\Requests\Tenant;

final class RefreshIntegrationRequest extends SettingsMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
