<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use Illuminate\Validation\Rule;

final class RecordCommunicationMessageReceiptRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receipt' => ['required', Rule::in(['READ', 'PLAYED'])],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'receipt' => (string) $this->validated('receipt'),
        ]);
    }
}
