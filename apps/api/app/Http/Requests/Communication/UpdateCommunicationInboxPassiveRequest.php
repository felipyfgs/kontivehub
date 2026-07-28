<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class UpdateCommunicationInboxPassiveRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'passive' => ['required', 'boolean'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'passive' => (bool) $this->validated('passive'),
        ]);
    }
}
