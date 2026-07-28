<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxInstallmentParcel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxInstallmentParcel */
final class TaxInstallmentParcelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInstallmentParcel $parcel */
        $parcel = $this->resource;

        return [
            'id' => $parcel->id,
            'tenant_id' => $parcel->tenant_id,
            'client_id' => $parcel->client_id,
            'order_id' => $parcel->order_id,
            'modality' => $parcel->modality?->value
                ?? $parcel->getRawOriginal('modality'),
            'parcel_key' => $parcel->parcel_key,
            'parcel_number' => $parcel->parcel_number,
            'status' => $parcel->status?->value
                ?? $parcel->getRawOriginal('status'),
            'source_status' => $parcel->source_status,
            'due_at' => $parcel->due_at?->toIso8601String(),
            'amount_cents' => $parcel->amount_cents,
            'document_available' => $parcel->document_available,
            'payment_status' => $parcel->payment_status?->value
                ?? $parcel->getRawOriginal('payment_status'),
            'paid_at' => $parcel->paid_at?->toIso8601String(),
            'tax_guide_id' => $parcel->tax_guide_id,
            'logical_key' => $parcel->logical_key,
        ];
    }
}
