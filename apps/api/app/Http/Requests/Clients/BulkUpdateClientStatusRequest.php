<?php

namespace App\Http\Requests\Clients;

use Illuminate\Foundation\Http\FormRequest;

class BulkUpdateClientStatusRequest extends FormRequest
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
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'is_active' => ['required', 'boolean'],
            'inactive_reason' => ['nullable', 'required_if:is_active,false', 'string', 'max:1000'],
            'office_id' => ['prohibited'],
        ];
    }
}
