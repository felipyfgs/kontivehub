<?php

namespace App\Http\Requests\Clients;

use App\Enums\TaxRegimeCode;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (array_key_exists('tax_regime', $this->all())) {
            $raw = $this->input('tax_regime');
            if ($raw === null || is_string($raw)) {
                $this->merge(['tax_regime' => TaxRegimeCode::fromInput($raw)?->value]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $officeId = app(CurrentOffice::class)->resolve()?->id;

        return [
            'legal_name' => ['sometimes', 'string', 'max:255'],
            'display_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
            'inactive_reason' => ['nullable', 'string', 'max:1000'],
            'legal_nature_code' => ['nullable', 'string', 'max:16'],
            'legal_nature_name' => ['nullable', 'string', 'max:255'],
            'company_size_code' => ['nullable', 'string', 'max:16'],
            'company_size_name' => ['nullable', 'string', 'max:255'],
            'tax_regime' => ['nullable', 'string', Rule::in(TaxRegimeCode::currentProjectionValues())],
            'work_department_id' => [
                'nullable',
                'integer',
                Rule::exists('work_departments', 'id')->where(
                    fn ($query) => $officeId
                        ? $query->where('office_id', $officeId)->where('is_active', true)
                        : $query->whereRaw('1 = 0')
                ),
            ],
            // imutáveis / proibidos
            'root_cnpj' => ['prohibited'],
            'cnpj' => ['prohibited'],
            'office_id' => ['prohibited'],
            'matrix_client_id' => ['prohibited'],
            'registration_source' => ['prohibited'],
            'registration_refreshed_at' => ['prohibited'],
        ];
    }
}
