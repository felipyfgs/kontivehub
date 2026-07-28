<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundSeedResult;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundSeedResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundSeedResult $result */
        $result = $this->resource;

        return [
            'profile' => (new OutboundCaptureProfileResource($result->profile))
                ->resolve($request),
            'series' => (new OutboundSeriesResource($result->series))
                ->resolve($request),
        ];
    }
}
