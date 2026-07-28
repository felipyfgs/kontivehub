<?php

namespace App\Http\Requests\Outbound;

class AdministerOutboundRequest extends OutboundRequest
{
    public function authorize(): bool
    {
        return $this->canAdminister();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
