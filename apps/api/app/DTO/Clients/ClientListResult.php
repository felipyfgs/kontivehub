<?php

namespace App\DTO\Clients;

use Illuminate\Pagination\LengthAwarePaginator;

final readonly class ClientListResult
{
    /**
     * @param  LengthAwarePaginator<int, ClientListItemData>  $paginator
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public LengthAwarePaginator $paginator,
        public array $stats,
    ) {}
}
