<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class MarkCommunicationInboxStateCleanRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'timestamp' => ['required', 'integer', 'min:1'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'action' => 'MARK_CLEAN',
            'timestamp' => (int) $this->validated('timestamp'),
        ]);
    }
}
