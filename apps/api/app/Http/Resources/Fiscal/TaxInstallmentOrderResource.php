<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxInstallmentOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxInstallmentOrder */
class TaxInstallmentOrderResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInstallmentOrder $order */
        $order = $this->resource;

        return [
            'id' => $order->id,
            'tenant_id' => $order->tenant_id,
            'client_id' => $order->client_id,
            'modality' => $order->modality?->value
                ?? $order->getRawOriginal('modality'),
            'regime' => $order->regime,
            'external_order_id' => $order->external_order_id,
            'situation' => $order->situation,
            'source_status' => $order->source_status,
            'requested_at' => $order->requested_at?->toIso8601String(),
            'consolidated_at' => $order->consolidated_at?->toIso8601String(),
            'parcel_count' => $order->parcel_count,
            'total_amount_cents' => $order->total_amount_cents,
            'source_system' => $order->source_system,
            'source_service' => $order->source_service,
            'observed_at' => $order->observed_at?->toIso8601String(),
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
