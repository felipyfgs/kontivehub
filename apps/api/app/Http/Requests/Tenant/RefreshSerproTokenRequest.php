<?php

namespace App\Http\Requests\Tenant;

final class RefreshSerproTokenRequest extends SerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string'],
        ];
    }
}
