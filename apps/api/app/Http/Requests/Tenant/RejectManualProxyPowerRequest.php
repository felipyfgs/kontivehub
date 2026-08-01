<?php

namespace App\Http\Requests\Tenant;

final class RejectManualProxyPowerRequest extends SerproAuthorizationMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [];
    }
}
