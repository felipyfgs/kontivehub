<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\FiscalIdentityData;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Policies\TenantFiscalCredentialPolicy;
use Illuminate\Validation\ValidationException;

final class StoreTenantFiscalIdentityRequest extends AuthenticatedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantFiscalCredentialPolicy::class)->manage($actor);
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'cnpj' => ['required', 'string', 'max:18'],
            'legal_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function identityData(): FiscalIdentityData
    {
        $validated = $this->validated();

        return new FiscalIdentityData(
            cnpj: (string) $validated['cnpj'],
            legalName: isset($validated['legal_name'])
                ? (string) $validated['legal_name']
                : null,
        );
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
