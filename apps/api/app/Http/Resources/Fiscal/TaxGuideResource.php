<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuide;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuide */
class TaxGuideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuide $guide */
        $guide = $this->resource;
        $current = $guide->relationLoaded('currentVersion')
            ? $guide->currentVersion
            : null;

        return [
            'id' => $guide->id,
            'tenant_id' => $guide->tenant_id,
            'client_id' => $guide->client_id,
            'establishment_id' => $guide->establishment_id,
            'operation_key' => $guide->operation_key,
            'system_code' => $guide->system_code,
            'service_code' => $guide->service_code,
            'operation_code' => $guide->operation_code,
            'competence_period_key' => $guide->competence_period_key,
            'debit_ref' => $guide->debit_ref,
            'logical_key' => $guide->logical_key,
            'payment_status' => $guide->payment_status?->value,
            'payment_confirmed_at' => $guide->payment_confirmed_at
                ?->toIso8601String(),
            'payment_source' => $guide->payment_source,
            'amount_cents' => $guide->amount_cents,
            'currency' => $guide->currency,
            'due_at' => $guide->due_at?->toIso8601String(),
            'identifier_code' => $guide->identifier_code,
            'current_version_id' => $guide->current_version_id,
            'current_version' => $current !== null
                ? (new TaxGuideVersionResource($current))->resolve($request)
                : null,
            'created_at' => $guide->created_at?->toIso8601String(),
        ];
    }
}
