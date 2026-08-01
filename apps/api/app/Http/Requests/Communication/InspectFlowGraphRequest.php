<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\FlowGraphInputData;

final class InspectFlowGraphRequest extends FlowRequest
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

    public function graphData(): FlowGraphInputData
    {
        $validated = $this->validated();

        return new FlowGraphInputData(
            graph: isset($validated['graph']) ? $validated['graph'] : null,
        );
    }
}
