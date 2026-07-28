<?php

namespace App\Http\Resources\Outbound;

use App\Http\Resources\PaginatedResourceCollection;

final class OutboundPendingCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = OutboundRetrievalRequestResource::class;

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return ['current_page', 'last_page', 'per_page', 'total'];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
