<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;

final class OperateConversationGatewayRequest extends ConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation();
    }
}
