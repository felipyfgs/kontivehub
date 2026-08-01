<?php

namespace App\Http\Requests\Tenant;

abstract class SerproAuthorizationMutationRequest extends SerproAuthorizationRequest
{
    protected function requiresMutationPermission(): bool
    {
        return true;
    }
}
