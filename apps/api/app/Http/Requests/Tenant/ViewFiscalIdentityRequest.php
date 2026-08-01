<?php

namespace App\Http\Requests\Tenant;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\TenantFiscalCredentialPolicy;
use Illuminate\Validation\ValidationException;

final class ViewFiscalIdentityRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantFiscalCredentialPolicy::class)->view($actor);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }
    }
}
