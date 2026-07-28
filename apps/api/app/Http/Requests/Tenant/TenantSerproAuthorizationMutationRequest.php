<?php

namespace App\Http\Requests\Tenant;

abstract class TenantSerproAuthorizationMutationRequest extends TenantSerproAuthorizationRequest
{
    protected function requiresMutationPermission(): bool
    {
        return true;
    }
}
