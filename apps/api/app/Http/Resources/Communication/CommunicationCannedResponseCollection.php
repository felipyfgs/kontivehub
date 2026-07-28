<?php

namespace App\Http\Resources\Communication;

use App\Http\Resources\PaginatedResourceCollection;

final class CommunicationCannedResponseCollection extends PaginatedResourceCollection
{
    /** @var class-string */
    public $collects = CommunicationCannedResponseResource::class;

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
