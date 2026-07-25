<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class ReplaceClientCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'category_ids' => ['present', 'array', 'max:25'],
            'category_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'office_id' => ['prohibited'],
            'client_id' => ['prohibited'],
        ];
    }
}
