<?php

namespace App\Http\Requests\Tenant;

final class RefreshTenantSerproTokenRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string'],
        ];
    }
}
