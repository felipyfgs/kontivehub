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
            'graph.nodes.*' => ['nullable', 'array'],
            'graph.nodes.*.id' => ['nullable'],
            'graph.nodes.*.type' => ['nullable'],
            'graph.nodes.*.label' => ['nullable'],
            'graph.nodes.*.position' => ['nullable', 'array'],
            'graph.nodes.*.position.x' => ['nullable'],
            'graph.nodes.*.position.y' => ['nullable'],
            'graph.nodes.*.data' => ['nullable', 'array'],
            'graph.nodes.*.data.*' => ['nullable'],
            'graph.nodes.*.data.options.*' => ['nullable'],
            'graph.edges' => ['required', 'array'],
            'graph.edges.*' => ['nullable', 'array'],
            'graph.edges.*.id' => ['nullable'],
            'graph.edges.*.source' => ['nullable'],
            'graph.edges.*.target' => ['nullable'],
            'graph.edges.*.label' => ['nullable'],
            'graph.edges.*.branch' => ['nullable'],
            'graph.edges.*.data' => ['nullable', 'array'],
            'graph.edges.*.data.*' => ['nullable'],
        ];
    }
}
