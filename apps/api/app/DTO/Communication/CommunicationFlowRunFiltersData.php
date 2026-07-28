<?php

namespace App\DTO\Communication;

final readonly class CommunicationFlowRunFiltersData
{
    public function __construct(
        public ?int $flowId,
        public ?string $status,
        public bool $activeOnly,
        public int $perPage,
        public int $page,
    ) {}
}
