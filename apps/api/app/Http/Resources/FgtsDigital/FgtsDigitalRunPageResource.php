<?php

namespace App\Http\Resources\FgtsDigital;

use App\Models\FgtsDigitalRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class FgtsDigitalRunPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, FgtsDigitalRun> $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = FgtsDigitalRunResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
