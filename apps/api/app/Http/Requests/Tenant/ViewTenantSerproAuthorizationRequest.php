<?php

namespace App\Http\Requests\Tenant;

final class ViewTenantSerproAuthorizationRequest extends TenantSerproAuthorizationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string'],
        ];
    }
}
