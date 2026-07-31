<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use Illuminate\Validation\Rule;

final class UpdateCommunicationConversationPresenceRequest extends CommunicationConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'presence' => ['required', Rule::in(['COMPOSING', 'PAUSED', 'RECORDING'])],
            'media' => ['nullable', Rule::in(['TEXT', 'AUDIO'])],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        $validated = $this->validated();
        $parameters = ['presence' => (string) $validated['presence']];
        if (isset($validated['media'])) {
            $parameters['media'] = (string) $validated['media'];
        }

        return $this->gatewayOperation($parameters);
    }
}
