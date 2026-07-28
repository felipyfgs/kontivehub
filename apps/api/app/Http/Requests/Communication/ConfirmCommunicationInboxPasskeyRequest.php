<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class ConfirmCommunicationInboxPasskeyRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'string', 'max:512'],
            'confirm' => ['required', 'boolean'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation([
            'id' => (string) $validated['id'],
            'confirm' => (bool) $validated['confirm'],
        ]);
    }
}
