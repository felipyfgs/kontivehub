<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundNumberFilters;

final class ListOutboundNumbersRequest extends ViewOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'gaps_only' => ['nullable', 'boolean'],
        ];
    }

    public function filters(): OutboundNumberFilters
    {
        return new OutboundNumberFilters(
            gapsOnly: (bool) ($this->validated('gaps_only') ?? false),
        );
    }
}
