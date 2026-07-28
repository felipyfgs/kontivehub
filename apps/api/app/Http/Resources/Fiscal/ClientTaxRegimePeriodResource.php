<?php

namespace App\Http\Resources\Fiscal;

use App\Models\ClientTaxRegimePeriod;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClientTaxRegimePeriod */
final class ClientTaxRegimePeriodResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var ClientTaxRegimePeriod $period */
        $period = $this->resource;

        return [
            'id' => $period->id,
            'tenant_id' => $period->tenant_id,
            'client_id' => $period->client_id,
            'regime_code' => $period->regime_code?->value,
            'effective_from' => $period->effective_from?->toDateString(),
            'effective_to' => $period->effective_to?->toDateString(),
            'source_system' => $period->source_system,
            'source_service' => $period->source_service,
            'observed_at' => $period->observed_at?->toIso8601String(),
        ];
    }
}
