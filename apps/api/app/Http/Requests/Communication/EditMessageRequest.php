<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\GatewayOperationData;

final class EditMessageRequest extends ConversationGatewayRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'max:65536'],
        ];
    }

    protected function prepareScopedValidation(): void
    {
        if (is_string($this->input('text'))) {
            $this->merge(['text' => trim($this->input('text'))]);
        }
    }

    public function gatewayData(): GatewayOperationData
    {
        return $this->gatewayOperation([
            'text' => (string) $this->validated('text'),
        ]);
    }
}
