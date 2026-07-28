<?php

namespace App\DTO\Communication;

final readonly class CommunicationCannedResponseFiltersData
{
    public function __construct(
        public bool $manageMode,
        public ?bool $isActive,
        public ?string $search,
        public int $perPage,
        public int $page,
        public bool $paginated,
    ) {}
}
