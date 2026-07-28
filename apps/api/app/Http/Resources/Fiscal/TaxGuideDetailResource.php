<?php

namespace App\Http\Resources\Fiscal;

use App\Models\TaxGuide;
use Illuminate\Http\Request;

/** @mixin TaxGuide */
final class TaxGuideDetailResource extends TaxGuideResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuide $guide */
        $guide = $this->resource;

        return parent::toArray($request) + [
            'versions' => $guide->relationLoaded('versions')
                ? TaxGuideVersionResource::collection(
                    $guide->versions,
                )->resolve($request)
                : [],
            'payment_confirmations' => $guide
                ->relationLoaded('paymentConfirmations')
                ? TaxGuidePaymentConfirmationResource::collection(
                    $guide->paymentConfirmations,
                )->resolve($request)
                : [],
        ];
    }
}
