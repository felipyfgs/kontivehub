<?php

namespace App\Http\Resources\Fiscal;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LengthAwarePaginator */
final class FiscalFindingPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $page */
        $page = $this->resource;
        $payload = $page->toArray();
        $payload['data'] = FiscalFindingResource::collection(
            $page->getCollection(),
        )->resolve($request);

        return $payload;
    }
}
