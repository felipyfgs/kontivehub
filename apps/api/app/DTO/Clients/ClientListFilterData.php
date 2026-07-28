<?php

namespace App\DTO\Clients;

final readonly class ClientListFilterData
{
    /**
     * @param  list<int>  $categoryIds
     * @param  list<string>  $taxRegimes
     * @param  list<string>  $procuracaoStatuses
     */
    public function __construct(
        public string $search,
        public bool $dashboard,
        public ?bool $isActive,
        public string $operationalFilter,
        public array $categoryIds,
        public array $taxRegimes,
        public array $procuracaoStatuses,
        public string $sort,
        public string $sortDirection,
        public int $perPage,
        public int $page,
    ) {}
}
