<?php

namespace App\Http\Resources\Outbound;

use App\DTO\Outbound\OutboundCapacityForecastData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCapacityForecastResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCapacityForecastData $result */
        $result = $this->resource;

        return [
            'competence' => $result->competence,
            'projection' => $result->projection,
            'latest_snapshot' => $result->latestSnapshot !== null
                ? (new OutboundCapacitySnapshotResource(
                    $result->latestSnapshot,
                ))->resolve($request)
                : null,
        ];
    }
}
