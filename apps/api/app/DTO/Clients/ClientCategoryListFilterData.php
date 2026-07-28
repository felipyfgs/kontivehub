<?php

namespace App\DTO\Clients;

final readonly class ClientCategoryListFilterData
{
    public function __construct(
        public bool $includeArchived,
    ) {}
}
