<?php

namespace App\Http\Resources\Fiscal;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class DeclarationProjectionPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator<int, array<string, mixed>> $page */
        $page = $this->resource;

        return $page->toArray();
    }
}
