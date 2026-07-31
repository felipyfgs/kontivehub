<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowCloneData;
use App\Models\CommunicationFlow;
use App\Models\CommunicationFlowVersion;

final class CloneCommunicationFlowVersionRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
        ];
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->string('name')->toString())]);
        }
    }

    public function cloneData(): FlowCloneData
    {
        $flow = $this->route('flow');
        $version = $this->route('version');
        $validated = $this->validated();

        return new FlowCloneData(
            name: isset($validated['name'])
                ? (string) $validated['name']
                : ($flow instanceof CommunicationFlow ? $flow->name : 'Fluxo').' (cópia)',
            fromVersionId: $version instanceof CommunicationFlowVersion
                ? (int) $version->id
                : null,
        );
    }
}
