<?php

namespace App\Http\Requests\Tenant;

final class ViewSerproAuthorizationRequest extends SerproAuthorizationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'environment' => ['sometimes', 'string'],
        ];
    }
}
