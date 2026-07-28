<?php

namespace App\Http\Requests\Tenant;

final class DownloadTenantSerproTermDraftRequest extends TenantSerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string'],
        ];
    }
}
