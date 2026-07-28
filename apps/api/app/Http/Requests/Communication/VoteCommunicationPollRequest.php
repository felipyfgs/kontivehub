<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class VoteCommunicationPollRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'option_names' => ['required', 'array', 'min:1', 'max:12'],
            'option_names.*' => ['required', 'string', 'max:256', 'distinct'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'option_names' => array_values($this->validated('option_names')),
        ]);
    }
}
