<?php

namespace App\Http\Requests\Outbound;

class OperateOutboundRequest extends OutboundRequest
{
    public function authorize(): bool
    {
        return $this->canOperate();
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
