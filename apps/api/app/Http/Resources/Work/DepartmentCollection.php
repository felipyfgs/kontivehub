<?php

namespace App\Http\Resources\Work;

use App\Http\Resources\PaginatedResourceCollection;

final class DepartmentCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = DepartmentResource::class;

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return [
            'current_page',
            'last_page',
            'per_page',
            'total',
        ];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
