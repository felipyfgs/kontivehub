<?php

namespace App\Http\Requests\Office;

use Illuminate\Foundation\Http\FormRequest;

class GrantOfficeTechnicalConsentRequest extends FormRequest
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
            'accepted' => ['required', 'accepted'],
            'version_code' => ['sometimes', 'string', 'max:40'],
            'office_id' => ['prohibited'],
        ];
    }
}
