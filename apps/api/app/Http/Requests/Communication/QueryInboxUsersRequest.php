<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;

final class QueryInboxUsersRequest extends InboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'users' => ['required', 'array', 'min:1', 'max:100'],
            'users.*' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/', 'distinct'],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'users' => array_values($this->validated('users')),
        ]);
    }
}
