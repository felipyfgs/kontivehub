<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Mutations\TaxGuideIssuanceResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin TaxGuideIssuanceResultData */
final class TaxGuideIssuanceResultResource extends JsonResource
{
    public static $wrap = 'data';

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TaxGuideIssuanceResultData $result */
        $result = $this->resource;

        return [
            'guide' => (new TaxGuidePublicResource($result->guide))->resolve($request),
            'version' => (new TaxGuideVersionPublicResource($result->version))->resolve($request),
            'reused' => $result->reused,
            'substituted' => $result->substituted,
            'payment_status' => $result->guide->payment_status?->value,
        ];
    }

    public function withResponse($request, $response): void
    {
        /** @var TaxGuideIssuanceResultData $result */
        $result = $this->resource;
        $response->setStatusCode($result->httpStatus());
    }
}
