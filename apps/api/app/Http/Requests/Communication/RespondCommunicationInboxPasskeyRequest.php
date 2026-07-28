<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class RespondCommunicationInboxPasskeyRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:512'],
            'client_data_json' => ['required', 'string', 'max:16384'],
            'authenticator_data' => ['required', 'string', 'max:16384'],
            'signature' => ['required', 'string', 'max:16384'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation($this->validated());
    }
}
