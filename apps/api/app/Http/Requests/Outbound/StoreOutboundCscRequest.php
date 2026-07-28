<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundCscData;

final class StoreOutboundCscRequest extends AccessOutboundSecretRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'csc' => ['required', 'string', 'min:1', 'max:100'],
            'csc_id' => ['required', 'string', 'min:1', 'max:20'],
        ];
    }

    public function cscData(): OutboundCscData
    {
        $validated = $this->validated();

        return new OutboundCscData(
            token: (string) $validated['csc'],
            identifier: (string) $validated['csc_id'],
        );
    }
}
