<?php

namespace App\Http\Resources\Fiscal;

use App\Http\Resources\PaginatedResourceCollection;

final class FiscalRegistrationLinkCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = FiscalRegistrationLinkResource::class;

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return [
            'current_page',
            'per_page',
            'total',
            'last_page',
        ];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
