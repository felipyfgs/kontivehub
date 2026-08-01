<?php

namespace App\Http\Requests\Tenant;

final class RemoveCertificateRequest extends SettingsMutationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'A remoção do certificado exige confirmação explícita.',
        ];
    }
}
