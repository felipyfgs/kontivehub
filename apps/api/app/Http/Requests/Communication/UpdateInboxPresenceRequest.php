<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use Illuminate\Validation\Rule;

final class UpdateInboxPresenceRequest extends InboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'presence' => ['required', Rule::in(['AVAILABLE', 'UNAVAILABLE'])],
            'force_active_delivery_receipts' => ['nullable', 'boolean'],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        $validated = $this->validated();
        $parameters = ['presence' => (string) $validated['presence']];
        if (array_key_exists('force_active_delivery_receipts', $validated)) {
            $parameters['force_active_delivery_receipts'] = (bool) $validated['force_active_delivery_receipts'];
        }

        return $this->gatewayOperation($parameters);
    }
}
