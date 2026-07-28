<?php

namespace App\Http\Requests\Outbound;

use App\DTO\Outbound\OutboundSeriesResetData;

final class ResetOutboundSeriesRequest extends AdministerOutboundRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'discovery_position' => ['required', 'integer', 'min:1'],
            'confirm' => ['required', 'accepted'],
        ];
    }

    public function resetData(): OutboundSeriesResetData
    {
        $validated = $this->validated();

        return new OutboundSeriesResetData(
            reason: (string) $validated['reason'],
            discoveryPosition: (int) $validated['discovery_position'],
        );
    }
}
