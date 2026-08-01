<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowCloneData;

final class CloneFlowRequest extends FlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'from_version_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareScopedValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function cloneData(): FlowCloneData
    {
        $validated = $this->validated();

        return new FlowCloneData(
            name: (string) $validated['name'],
            fromVersionId: isset($validated['from_version_id'])
                ? (int) $validated['from_version_id']
                : null,
        );
    }
}
