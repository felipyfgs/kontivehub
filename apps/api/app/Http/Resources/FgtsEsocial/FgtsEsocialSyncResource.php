<?php

namespace App\Http\Resources\FgtsEsocial;

use App\DTO\Esocial\FgtsEsocialSyncResultData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsEsocialSyncResultData $result */
        $result = $this->resource;

        return [
            'status' => (new FgtsEsocialCompetenceResource(
                $result->status,
            ))->resolve($request),
            'projection' => $result->projection->toArray(),
            'events_count' => $result->eventsCount,
            'evidences' => FgtsEsocialEventResource::collection(
                $result->evidences,
            )->resolve($request),
            'coverage' => $result->coverage,
        ];
    }
}
