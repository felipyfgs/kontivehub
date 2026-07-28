<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\DeclarationCatalogData;
use App\Services\Fiscal\Declarations\DeclarationIntegrationCoverageService;
use App\Services\Fiscal\Declarations\DeclarationOperationCatalogService;
use App\Services\Fiscal\Declarations\TaxObligationCatalogService;

final readonly class ViewDeclarationCatalogAction
{
    public function __construct(
        private TaxObligationCatalogService $catalog,
        private DeclarationIntegrationCoverageService $integrationCoverage,
        private DeclarationOperationCatalogService $operationCatalog,
    ) {}

    public function handle(): DeclarationCatalogData
    {
        return new DeclarationCatalogData(
            obligations: $this->catalog->catalogPayload(),
            calendar: $this->catalog->currentCalendar(),
            integrationCoverage: $this->integrationCoverage->publicCoverage(),
            operationCatalog: $this->operationCatalog->publicCatalog(),
        );
    }
}
