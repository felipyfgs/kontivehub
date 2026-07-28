<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\SimplesMeiLocalProjectionData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SimplesMeiLocalProjectionData */
final class SimplesMeiLocalProjectionResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SimplesMeiLocalProjectionData $data */
        $data = $this->resource;

        return [
            'data' => $data->items,
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ];
    }
}
