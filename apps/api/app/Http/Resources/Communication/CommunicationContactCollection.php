<?php

namespace App\Http\Resources\Communication;

use App\Http\Resources\PaginatedResourceCollection;

final class CommunicationContactCollection extends PaginatedResourceCollection
{
    public $collects = CommunicationContactResource::class;

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return ['current_page', 'last_page', 'total'];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
