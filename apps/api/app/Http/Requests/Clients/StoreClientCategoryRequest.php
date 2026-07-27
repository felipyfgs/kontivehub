<?php

namespace App\Http\Requests\Clients;

use App\Models\ClientCategory;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (! $this->has('name')) {
            return;
        }

        $name = preg_replace('/\s+/u', ' ', trim((string) $this->input('name')))
            ?? trim((string) $this->input('name'));

        $this->merge([
            'name' => $name,
            '_name_key' => ClientCategory::normalizeNameKey($name),
        ]);
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenantId = app(CurrentTenant::class)->id();

        return [
            'name' => ['required', 'string', 'max:80'],
            '_name_key' => [
                'required',
                'string',
                'max:80',
                Rule::unique('client_categories', 'name_key')->where('tenant_id', $tenantId),
            ],
            'color' => ['required', 'string', Rule::in(ClientCategory::COLORS)],
            'tenant_id' => ['prohibited'],
            'created_by' => ['prohibited'],
            'is_active' => ['prohibited'],
        ];
    }
}
