<?php

namespace App\Http\Requests\Outbound;

class ViewOutboundRequest extends OutboundRequest
{
    public function authorize(): bool
    {
        return $this->canView();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
