<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationGatewayOperationData;

final class ResolveCommunicationInboxLinkRequest extends CommunicationInboxGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'link' => ['required', 'string', 'max:2048'],
        ];
    }

    protected function prepareCommunicationValidation(): void
    {
        if (is_string($this->input('link'))) {
            $this->merge(['link' => trim($this->input('link'))]);
        }
    }

    public function gatewayData(): CommunicationGatewayOperationData
    {
        return $this->gatewayOperation([
            'link' => (string) $this->validated('link'),
        ]);
    }
}
