<?php

namespace App\Http\Requests\Outbound;

class AccessOutboundSecretRequest extends OutboundRequest
{
    public function authorize(): bool
    {
        return $this->canAccessSecret();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
