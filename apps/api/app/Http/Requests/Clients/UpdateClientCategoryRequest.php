<?php

namespace App\Http\Requests\Clients;

use App\Models\ClientCategory;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientCategoryRequest extends FormRequest
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
        /** @var ClientCategory|null $category */
        $category = $this->route('clientCategory');
        $officeId = app(CurrentOffice::class)->id();

        return [
            'name' => ['sometimes', 'required', 'string', 'max:80'],
            '_name_key' => [
                'required_with:name',
                'string',
                'max:80',
                Rule::unique('client_categories', 'name_key')
                    ->where('office_id', $officeId)
                    ->ignore($category?->id),
            ],
            'color' => ['sometimes', 'required', 'string', Rule::in(ClientCategory::COLORS)],
            'is_active' => ['sometimes', 'boolean'],
            'office_id' => ['prohibited'],
            'created_by' => ['prohibited'],
        ];
    }
}
