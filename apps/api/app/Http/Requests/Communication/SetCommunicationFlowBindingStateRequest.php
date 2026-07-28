<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowBindingUpdateData;

final class SetCommunicationFlowBindingStateRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'published_version_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function bindingData(): CommunicationFlowBindingUpdateData
    {
        $validated = $this->validated();

        return new CommunicationFlowBindingUpdateData(
            lockVersion: (int) $validated['lock_version'],
            publishedVersionId: isset($validated['published_version_id'])
                ? (int) $validated['published_version_id']
                : null,
            hasPublishedVersionId: array_key_exists('published_version_id', $validated),
            enabled: null,
        );
    }
}
