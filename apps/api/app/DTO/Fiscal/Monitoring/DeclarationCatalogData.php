<?php

namespace App\DTO\Fiscal\Monitoring;

use App\Models\TaxDeadlineCalendarVersion;

final readonly class DeclarationCatalogData
{
    /**
     * @param  list<array<string, mixed>>  $obligations
     * @param  array<string, mixed>  $integrationCoverage
     * @param  array<string, mixed>  $operationCatalog
     */
    public function __construct(
        public array $obligations,
        public ?TaxDeadlineCalendarVersion $calendar,
        public array $integrationCoverage,
        public array $operationCatalog,
    ) {}
}
