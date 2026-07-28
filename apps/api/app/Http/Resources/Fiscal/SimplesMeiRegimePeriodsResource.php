<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\SimplesMeiRegimePeriodsData;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SimplesMeiRegimePeriodsData */
final class SimplesMeiRegimePeriodsResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SimplesMeiRegimePeriodsData $data */
        $data = $this->resource;

        return [
            'data' => ClientTaxRegimePeriodResource::collection(
                $data->periods,
            )->resolve($request),
            'current_tax_regime' => $data->currentTaxRegime
                instanceof BackedEnum
                ? $data->currentTaxRegime->value
                : $data->currentTaxRegime,
        ];
    }
}
