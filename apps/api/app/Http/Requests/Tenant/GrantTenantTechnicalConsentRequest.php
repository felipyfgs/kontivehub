<?php

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class GrantTenantTechnicalConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('tenant_id');
        if ($this->isJson() && $this->json() !== null) {
            $this->json()->remove('tenant_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'accepted' => ['required', 'accepted'],
            'version_code' => ['sometimes', 'string', 'max:40'],
            'tenant_id' => ['prohibited'],
        ];
    }
}
