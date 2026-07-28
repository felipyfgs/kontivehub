<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class QueryCommunicationInboxQrLinkRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'revoke' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'revoke' => (bool) ($this->validated('revoke') ?? false),
        ]);
    }
}
