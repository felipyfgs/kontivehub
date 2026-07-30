<?php

namespace App\Http\Resources\Communication;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LengthAwarePaginator */
final class ConversationBulkOperationItemPageResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var LengthAwarePaginator $page */
        $page = $this->resource;

        return [
            'data' => ConversationBulkOperationItemResource::collection(
                $page->getCollection(),
            )->resolve($request),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ];
    }
}
