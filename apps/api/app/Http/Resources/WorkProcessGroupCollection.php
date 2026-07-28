<?php

namespace App\Http\Resources;

final class WorkProcessGroupCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = WorkProcessGroupResource::class;

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
