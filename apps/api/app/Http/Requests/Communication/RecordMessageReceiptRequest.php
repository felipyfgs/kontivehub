<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use Illuminate\Validation\Rule;

final class RecordMessageReceiptRequest extends ConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'receipt' => ['required', Rule::in(['READ', 'PLAYED'])],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'receipt' => (string) $this->validated('receipt'),
        ]);
    }
}
