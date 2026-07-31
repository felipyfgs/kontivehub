<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;
use Illuminate\Validation\Rule;

final class UpdateCommunicationInboxDisappearingRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'timer_seconds' => ['required', 'integer', Rule::in([0, 86400, 604800, 7776000])],
        ];
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'timer_seconds' => (int) $this->validated('timer_seconds'),
        ]);
    }
}
