<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxInstallmentPayment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxInstallmentPayment */
final class TaxInstallmentPaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInstallmentPayment $payment */
        $payment = $this->resource;

        return [
            'id' => $payment->id,
            'tenant_id' => $payment->tenant_id,
            'client_id' => $payment->client_id,
            'order_id' => $payment->order_id,
            'parcel_id' => $payment->parcel_id,
            'modality' => $payment->modality?->value
                ?? $payment->getRawOriginal('modality'),
            'status' => $payment->status?->value
                ?? $payment->getRawOriginal('status'),
            'amount_cents' => $payment->amount_cents,
            'paid_at' => $payment->paid_at?->toIso8601String(),
            'payment_ref' => $payment->payment_ref,
        ];
    }
}
