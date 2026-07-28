<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Mutations\TaxGuidePaymentResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuidePaymentResultData */
final class TaxGuidePaymentResultResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuidePaymentResultData $result */
        $result = $this->resource;

        return [
            'guide' => (new TaxGuidePublicResource($result->guide))->resolve($request),
            'confirmation' => $result->confirmation !== null
                ? (new TaxGuidePaymentConfirmationResource($result->confirmation))->resolve($request)
                : null,
            'lookup_status' => $result->lookupStatus,
        ];
    }
}
