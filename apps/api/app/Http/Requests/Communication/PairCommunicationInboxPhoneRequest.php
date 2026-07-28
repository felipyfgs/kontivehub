<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class PairCommunicationInboxPhoneRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'regex:/^\+[1-9][0-9]{7,14}$/'],
            'show_push_notification' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation([
            'phone' => (string) $validated['phone'],
            'show_push_notification' => (bool) ($validated['show_push_notification'] ?? false),
        ]);
    }
}
