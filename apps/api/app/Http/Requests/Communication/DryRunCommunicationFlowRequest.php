<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationFlowGraphInputData;

final class DryRunCommunicationFlowRequest extends CommunicationFlowRequest
{
    public function authorize(): bool
    {
        return $this->canManageFlow();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...$this->graphRules('sometimes'),
            'context' => ['sometimes', 'array'],
            'context.contact_name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'context.conversation_status' => ['sometimes', 'nullable', 'string', 'max:64'],
            'context.last_inbound_text' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'context.question_answers' => ['sometimes', 'array'],
            'context.question_answers.*' => ['string', 'max:500'],
        ];
    }

    public function graphData(): CommunicationFlowGraphInputData
    {
        $validated = $this->validated();

        return new CommunicationFlowGraphInputData(
            graph: isset($validated['graph']) ? $validated['graph'] : null,
            context: isset($validated['context']) && is_array($validated['context'])
                ? $validated['context']
                : [],
        );
    }
}
