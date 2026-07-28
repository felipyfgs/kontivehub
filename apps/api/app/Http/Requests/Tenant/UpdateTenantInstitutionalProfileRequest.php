<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantInstitutionalProfileUpdateData;
use App\Rules\ValidCnpj;

final class UpdateTenantInstitutionalProfileRequest extends TenantSettingsMutationRequest
{
    protected function prepareTenantSettingsValidation(): void
    {
        // CNPJ vazio = omitir / limpar depois; não validar como CNPJ.
        if ($this->exists('cnpj') && trim((string) $this->input('cnpj')) === '') {
            $this->merge(['cnpj' => null]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cnpj' => ['sometimes', 'nullable', 'string', new ValidCnpj],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'institutional_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'institutional_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'confirm_cnpj_change' => ['sometimes', 'boolean'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tenant_id.prohibited' => 'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
            'institutional_email.email' => 'Informe um e-mail institucional válido.',
        ];
    }

    public function toDto(): TenantInstitutionalProfileUpdateData
    {
        return new TenantInstitutionalProfileUpdateData(
            attributes: $this->validated(),
            actorUserId: $this->actor()->id,
        );
    }
}
