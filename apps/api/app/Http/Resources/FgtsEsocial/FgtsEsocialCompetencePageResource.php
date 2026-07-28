<?php

namespace App\Http\Resources\FgtsEsocial;

use App\Models\FgtsCompetenceStatus;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsEsocialCompetencePageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, FgtsCompetenceStatus> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = FgtsEsocialCompetenceResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
