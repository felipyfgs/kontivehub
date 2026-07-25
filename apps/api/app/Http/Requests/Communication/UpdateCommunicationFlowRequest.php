<?php

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCommunicationFlowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'min:2', 'max:160'],
            'status' => ['sometimes', 'required', 'in:paused,active'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
