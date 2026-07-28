<?php

namespace App\Http\Resources\Communication;

use App\DTO\Communication\CommunicationEventPageData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class CommunicationEventSyncResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var CommunicationEventPageData $page */
        $page = $this->resource;

        return [
            'data' => CommunicationEventResource::collection(
                $page->events,
            )->resolve($request),
            'meta' => [
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->hasMore,
            ],
        ];
    }
}
