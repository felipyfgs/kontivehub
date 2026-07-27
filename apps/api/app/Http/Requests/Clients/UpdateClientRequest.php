<?php

namespace App\Http\Requests\Clients;

use App\Enums\TaxRegimeCode;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->resolve()?->id;

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
                    fn ($query) => $tenantId
                        ? $query->where('tenant_id', $tenantId)->where('is_active', true)
                        : $query->whereRaw('1 = 0')
                ),
            ],
            // imutáveis / proibidos
            'root_cnpj' => ['prohibited'],
            'cnpj' => ['prohibited'],
            'tenant_id' => ['prohibited'],
            'registration_source' => ['prohibited'],
            'registration_refreshed_at' => ['prohibited'],
        ];
    }
}
