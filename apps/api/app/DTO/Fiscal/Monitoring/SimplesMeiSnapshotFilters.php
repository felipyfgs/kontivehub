<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SimplesMeiSnapshotFilters
{
    public function __construct(
        public int $perPage,
        public ?string $systemCode,
    ) {}
}
