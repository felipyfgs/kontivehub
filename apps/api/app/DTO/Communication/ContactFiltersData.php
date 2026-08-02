<?php

namespace App\DTO\Communication;

final readonly class ContactFiltersData
{
    public function __construct(
        public ?string $search,
        public bool $phoneSearch,
        public ?bool $isActive,
        public bool $includeInactive,
        public ?bool $isProvisional,
        public ?bool $linked,
        public string $sort,
        public string $direction,
        public int $perPage,
        public int $page,
        public ?int $inboxId = null,
    ) {}
}
