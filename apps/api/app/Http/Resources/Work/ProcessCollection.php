<?php

namespace App\Http\Resources\Work;

use App\Http\Resources\PaginatedResourceCollection;

final class ProcessCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = ProcessResource::class;

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
