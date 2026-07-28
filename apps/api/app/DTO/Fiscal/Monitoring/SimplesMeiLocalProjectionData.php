<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SimplesMeiLocalProjectionData
{
    /** @param list<array<string, mixed>> $items */
    public function __construct(
        public array $items,
    ) {}
}
