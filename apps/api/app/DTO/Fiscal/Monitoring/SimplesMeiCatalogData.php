<?php

namespace App\DTO\Fiscal\Monitoring;

final readonly class SimplesMeiCatalogData
{
    /** @param list<array<string, mixed>> $operations */
    public function __construct(
        public array $operations,
        public string $module,
        public bool $moduleEnabled,
        public bool $mutatingEnabled,
    ) {}
}
