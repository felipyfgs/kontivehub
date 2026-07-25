<?php

namespace App\Http\Requests\Office;

use Illuminate\Foundation\Http\FormRequest;

class RemoveOfficeCanonicalCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->request->remove('office_id');
        if ($this->isJson() && $this->json() !== null) {
            $this->json()->remove('office_id');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
            'office_id' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.accepted' => 'A remoção do certificado A1 exige confirmação explícita.',
        ];
    }
}
