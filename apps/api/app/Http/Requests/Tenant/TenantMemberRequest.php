<?php

namespace App\Http\Requests\Tenant;

use App\Exceptions\ActivationApiException;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\TenantMemberPolicy;
use App\Services\Activation\ActivationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

abstract class TenantMemberRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        if (! $actor instanceof User) {
            return false;
        }

        return $this->authorizeMemberOperation(
            app(TenantMemberPolicy::class),
            $actor,
        );
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

    /**
     * @throws AuthorizationException
     */
    protected function failedAuthorization(): void
    {
        throw ActivationApiException::fromDomain(
            ActivationException::forbidden(
                'Ação não autorizada para o perfil atual.',
            ),
        );
    }

    abstract protected function authorizeMemberOperation(
        TenantMemberPolicy $policy,
        User $actor,
    ): bool;
}
