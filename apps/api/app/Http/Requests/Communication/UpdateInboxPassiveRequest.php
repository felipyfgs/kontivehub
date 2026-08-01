<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;

final class UpdateInboxPassiveRequest extends InboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'passive' => ['required', 'boolean'],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'passive' => (bool) $this->validated('passive'),
        ]);
    }
}
