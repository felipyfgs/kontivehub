<?php

namespace App\Http\Requests\Tenant;

use App\Rules\ValidCnpj;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantInstitutionalProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Policy no controller
    }

    protected function prepareForValidation(): void
    {
        // Defesa em profundidade: nunca aceitar tenant_id do client.
        $this->request->remove('tenant_id');
        if ($this->isJson() && $this->json() !== null) {
            $this->json()->remove('tenant_id');
        }

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
}
