<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class OperateCommunicationConversationGatewayRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation();
    }
}
