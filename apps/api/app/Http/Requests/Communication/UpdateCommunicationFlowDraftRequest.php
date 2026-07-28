<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowDraftData;

final class UpdateCommunicationFlowDraftRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            ...$this->graphRules('required'),
        ];
    }

    public function draftData(): CommunicationFlowDraftData
    {
        $validated = $this->validated();

        return new CommunicationFlowDraftData(
            graph: $validated['graph'],
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
