<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkUpdateClientCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'operation' => ['required', 'string', Rule::in(['add', 'remove'])],
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'category_ids' => ['required', 'array', 'min:1', 'max:25'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'office_id' => ['prohibited'],
        ];
    }
}
