<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class ReactToCommunicationMessageRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'emoji' => ['present', 'nullable', 'string', 'max:32'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $emoji = $this->validated('emoji');

        return $this->gatewayOperation([
            'emoji' => $emoji === null ? '' : trim((string) $emoji),
        ]);
    }
}
