<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundProfileActivationData;

final class ActivateOutboundProfileRequest extends AdministerOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'mandate_reference' => ['required', 'string', 'max:255'],
            'allowlisted' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareOutboundValidation(): void
    {
        if (is_string($this->input('mandate_reference'))) {
            $this->merge([
                'mandate_reference' => trim($this->input('mandate_reference')),
            ]);
        }
    }

    public function activationData(): OutboundProfileActivationData
    {
        $validated = $this->validated();

        return new OutboundProfileActivationData(
            mandateReference: (string) $validated['mandate_reference'],
            allowlisted: (bool) ($validated['allowlisted'] ?? false),
        );
    }
}
