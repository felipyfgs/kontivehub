<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowDraftData;

final class UpdateFlowDraftRequest extends FlowRequest
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

    public function draftData(): FlowDraftData
    {
        $validated = $this->validated();

        return new FlowDraftData(
            graph: $validated['graph'],
            lockVersion: (int) $validated['lock_version'],
        );
    }
}
