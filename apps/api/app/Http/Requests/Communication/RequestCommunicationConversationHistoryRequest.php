<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class RequestCommunicationConversationHistoryRequest extends CommunicationConversationGatewayRequest
{
    protected function requiresInboxManagement(): bool
    {
        return true;
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'count' => ['required', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'count' => (int) $this->validated('count'),
        ]);
    }
}
