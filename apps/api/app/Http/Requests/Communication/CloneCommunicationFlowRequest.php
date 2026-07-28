<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowCloneData;

final class CloneCommunicationFlowRequest extends CommunicationFlowRequest
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

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function cloneData(): CommunicationFlowCloneData
    {
        $validated = $this->validated();

        return new CommunicationFlowCloneData(
            name: (string) $validated['name'],
            fromVersionId: isset($validated['from_version_id'])
                ? (int) $validated['from_version_id']
                : null,
        );
    }
}
