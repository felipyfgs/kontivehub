<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\EventPageData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class EventSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var EventPageData $page */
        $page = $this->resource;

        return [
            'data' => EventResource::collection(
                $page->events,
            )->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->hasMore,
            ],
        ];
    }
}
