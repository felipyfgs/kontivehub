<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxInstallmentOrder;
use Illuminate\Http\Request;

/** @mixin TaxInstallmentOrder */
final class TaxInstallmentOrderDetailResource extends TaxInstallmentOrderResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxInstallmentOrder $order */
        $order = $this->resource;

        return [
            ...parent::toArray($request),
            'parcels' => TaxInstallmentParcelResource::collection(
                $order->parcels,
            )->resolve($request),
            'payments' => TaxInstallmentPaymentResource::collection(
                $order->payments,
            )->resolve($request),
        ];
    }
}
