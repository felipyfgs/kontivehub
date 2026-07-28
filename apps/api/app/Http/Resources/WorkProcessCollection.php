<?php

namespace App\Http\Resources;

final class WorkProcessCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = WorkProcessResource::class;

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
