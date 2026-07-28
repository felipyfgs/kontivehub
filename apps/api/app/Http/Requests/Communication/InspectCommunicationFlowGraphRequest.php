<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowGraphInputData;

final class InspectCommunicationFlowGraphRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->graphRules('sometimes');
    }

    public function graphData(): CommunicationFlowGraphInputData
    {
        $validated = $this->validated();

        return new CommunicationFlowGraphInputData(
            graph: isset($validated['graph']) ? $validated['graph'] : null,
        );
    }
}
