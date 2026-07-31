<?php

namespace App\Http\Resources\Communication;

use App\Http\Resources\PaginatedResourceCollection;

final class ContactCollection extends PaginatedResourceCollection
{
    public $collects = ContactResource::class;

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
