<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

abstract class DeclarationHubWriteRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        if (! $this->user() instanceof User) {
            return false;
        }

        $role = app(CurrentTenant::class)->role();

        return $role !== null
            && in_array($role, [TenantRole::TenantAdmin, TenantRole::TenantUser], true);
    }

    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->query->has('tenant_id')
            || $this->request->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }

    protected function failedAuthorization(): void
    {
        throw new AuthorizationException('Ação não autorizada para o perfil atual.');
    }
}
