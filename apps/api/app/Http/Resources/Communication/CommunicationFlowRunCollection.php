<?php

namespace App\Http\Resources\Communication;

use App\Http\Resources\PaginatedResourceCollection;

final class CommunicationFlowRunCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = CommunicationFlowRunResource::class;

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return [
            'current_page',
            'last_page',
            'total',
        ];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
