<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use Illuminate\Validation\Rule;

final class RecoverCommunicationMessageRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'operation' => ['required', Rule::in(['UNAVAILABLE', 'MEDIA_RETRY'])],
        ];
    }

    public function commandType(): GatewayCommandType
    {
        return $this->validated('operation') === 'MEDIA_RETRY'
            ? GatewayCommandType::RequestMediaRetry
            : GatewayCommandType::RequestUnavailableMessage;
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation();
    }
}
