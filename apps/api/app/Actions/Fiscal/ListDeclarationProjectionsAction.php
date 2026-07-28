<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\DeclarationProjectionFilters;
use App\Models\Tenant;
use App\Services\Fiscal\Declarations\DeclarationDctfwebEnrichmentService;
use App\Services\Fiscal\Declarations\DeclarationHubQueryService;
use App\Services\Fiscal\Declarations\DeclarationPgdasdEnrichmentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class ListDeclarationProjectionsAction
{
    public function __construct(
        private DeclarationHubQueryService $hub,
        private DeclarationPgdasdEnrichmentService $pgdasdEnrichment,
        private DeclarationDctfwebEnrichmentService $dctfwebEnrichment,
    ) {}

    /** @return LengthAwarePaginator<int, array<string, mixed>> */
    public function handle(
        Tenant $tenant,
        DeclarationProjectionFilters $filters,
    ): LengthAwarePaginator {
        $page = $this->hub->list($tenant, $filters->toArray());
        $enriched = $this->pgdasdEnrichment->enrichPublicList(
            $tenant,
            $page->getCollection(),
            true,
        );
        $enriched = $this->dctfwebEnrichment->enrichPublicRows(
            $tenant,
            $enriched,
            $filters->clientId,
        );
        $page->setCollection(collect($enriched));

        return $page;
    }
}
