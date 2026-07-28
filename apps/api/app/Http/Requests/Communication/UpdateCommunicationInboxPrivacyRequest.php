<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;
use Illuminate\Validation\Rule;

final class UpdateCommunicationInboxPrivacyRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', Rule::in(['last', 'profile', 'readreceipts', 'online'])],
            'value' => ['required', Rule::in(['all', 'contacts', 'contact_blacklist', 'none', 'match_last_seen'])],
        ];
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        $validated = $this->validated();

        return $this->gatewayOperation([
            'name' => (string) $validated['name'],
            'value' => (string) $validated['value'],
        ]);
    }
}
