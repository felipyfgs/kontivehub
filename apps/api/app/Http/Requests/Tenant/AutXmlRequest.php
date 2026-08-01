<?php

namespace App\Http\Requests\Tenant;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\TenantAutXmlPolicy;
use Illuminate\Validation\ValidationException;

abstract class AutXmlRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        $policy = app(TenantAutXmlPolicy::class);

        return $this->requiresManagementPermission()
            ? $policy->manage($actor)
            : $policy->view($actor);
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    protected function requiresManagementPermission(): bool
    {
        return false;
    }
}
