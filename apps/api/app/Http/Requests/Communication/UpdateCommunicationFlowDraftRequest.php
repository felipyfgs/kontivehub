<?php

namespace App\Http\Requests\Communication;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateCommunicationFlowDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'graph' => ['required', 'array'],
            'graph.nodes' => ['required', 'array'],
            'graph.edges' => ['required', 'array'],
        ];
    }
}
