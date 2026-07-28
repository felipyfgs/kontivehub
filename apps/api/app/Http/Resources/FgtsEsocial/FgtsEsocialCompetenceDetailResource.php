<?php

namespace App\Http\Resources\FgtsEsocial;

use App\DTO\Esocial\FgtsEsocialCompetenceDetailData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialCompetenceDetailResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var FgtsEsocialCompetenceDetailData $detail */
        $detail = $this->resource;

        return [
            'data' => (new FgtsEsocialCompetenceResource(
                $detail->status,
            ))->resolve($request),
            'events' => FgtsEsocialEventResource::collection(
                $detail->events,
            )->resolve($request),
            'coverage' => $detail->coverage,
        ];
    }
}
