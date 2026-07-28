<?php

namespace App\Http\Resources;

final class WorkTaskQueueCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = WorkTaskQueueItemResource::class;

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
