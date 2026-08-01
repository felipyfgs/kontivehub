<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;

final class QueryInboxQrLinkRequest extends InboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'revoke' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'revoke' => (bool) ($this->validated('revoke') ?? false),
        ]);
    }
}
