<?php

namespace App\Http\Resources;

final class WorkCalendarDayCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = WorkCalendarTaskResource::class;

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
