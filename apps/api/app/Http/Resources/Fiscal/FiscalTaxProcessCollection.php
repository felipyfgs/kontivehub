<?php

namespace App\Http\Resources\Fiscal;

use App\Http\Resources\PaginatedResourceCollection;

final class FiscalTaxProcessCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = FiscalTaxProcessResource::class;

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
