<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;

final class ClientCollection extends PaginatedResourceCollection
{
    public $collects = ClientListResource::class;

    /** @param array<string, mixed> $stats */
    public function __construct($resource, private readonly array $stats)
    {
        parent::__construct($resource);
    }

    /**
     * @param  array<string, mixed>  $paginated
     * @param  array{links: array<string, mixed>, meta: array<string, mixed>}  $default
     * @return array<string, mixed>
     */
    public function paginationInformation(Request $request, array $paginated, array $default): array
    {
        $information = parent::paginationInformation($request, $paginated, $default);
        $information['meta']['stats'] = $this->stats;

        return $information;
    }

    /** @return list<string> */
    protected function paginationMetaFields(): array
    {
        return ['current_page', 'last_page', 'per_page', 'total'];
    }

    protected function includesPaginationLinks(): bool
    {
        return false;
    }
}
