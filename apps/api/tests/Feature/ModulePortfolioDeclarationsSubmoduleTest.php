<?php

namespace Tests\Feature;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalMutability;
use App\Enums\FiscalSituation;
use App\Enums\TaxObligationApplicability;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulePortfolioDeclarationsSubmoduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_declarations_known_submodules_include_obligation_tabs(): void
    {
        $this->assertSame(
            [
                'PGDAS',
                'DEFIS',
                'DASN_SIMEI',
                'DCTFWEB',
                'MIT',
                'FGTS',
                'DIRF',
            ],
            FiscalModuleKey::Declarations->knownSubmodules(),
        );
    }

    public function test_pgdas_submodule_filters_to_pgdas_d_projections(): void
    {
        [$tenant, $pgdasClient, $defisClient] = $this->seedObligationClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $page = $portfolio->clients(
            $tenant,
            FiscalModuleKey::Declarations,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDAS', 'per_page' => 50]),
        );

        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($pgdasClient->id, $ids);
        $this->assertNotContains($defisClient->id, $ids);

        $row = collect($page->items())->first(fn ($r) => $r->clientId === $pgdasClient->id);
        $this->assertNotNull($row);
        $this->assertSame('PGDAS_D', $row->detail['next_obligation_code'] ?? null);
        $this->assertSame('PGDAS', $row->detail['submodule'] ?? null);
    }

    public function test_dirf_submodule_returns_empty_unsupported_coverage(): void
    {
        [$tenant] = $this->seedObligationClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $overview = $portfolio->overview(
            $tenant,
            FiscalModuleKey::Declarations,
            ModulePortfolioFilters::fromRequest(['submodule' => 'DIRF']),
        );
        $this->assertSame(FiscalCoverage::Unsupported->value, $overview->coverage);
        $this->assertSame(0, $overview->totalClients);

        $page = $portfolio->clients(
            $tenant,
            FiscalModuleKey::Declarations,
            ModulePortfolioFilters::fromRequest(['submodule' => 'DIRF']),
        );
        $this->assertSame(0, $page->total());
    }

    public function test_dasn_simei_and_mit_use_isolated_obligation_populations(): void
    {
        [$tenant, , , $dasnClient, $mitClient] = $this->seedObligationClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        foreach ([
            'DASN_SIMEI' => [$dasnClient->id, 'DASN_SIMEI'],
            'MIT' => [$mitClient->id, 'MIT'],
        ] as $submodule => [$expectedClientId, $expectedObligation]) {
            $filters = ModulePortfolioFilters::fromRequest([
                'submodule' => $submodule,
                'per_page' => 50,
            ]);
            $overview = $portfolio->overview($tenant, FiscalModuleKey::Declarations, $filters);
            $page = $portfolio->clients($tenant, FiscalModuleKey::Declarations, $filters);

            $this->assertSame(1, $overview->totalClients, $submodule);
            $this->assertSame(1, $page->total(), $submodule);
            $this->assertSame($expectedClientId, $page->items()[0]->clientId, $submodule);
            $this->assertSame(
                $expectedObligation,
                $page->items()[0]->detail['next_obligation_code'] ?? null,
                $submodule,
            );
        }
    }

    public function test_overview_exposes_stable_counts_for_all_obligation_tabs(): void
    {
        [$tenant] = $this->seedObligationClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $overview = $portfolio->overview(
            $tenant,
            FiscalModuleKey::Declarations,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDAS']),
        );

        $this->assertSame(1, $overview->totalClients);
        $this->assertSame([
            'PGDAS' => 1,
            'DEFIS' => 1,
            'DASN_SIMEI' => 1,
            'DCTFWEB' => 0,
            'MIT' => 1,
            'FGTS' => 0,
            'DIRF' => 0,
        ], $overview->metrics['tab_counts'] ?? null);
    }

    /**
     * @return array{0: Tenant, 1: Client, 2: Client, 3: Client, 4: Client}
     */
    private function seedObligationClients(): array
    {
        $tenant = Tenant::factory()->create();
        $this->ensureDeclarationsCategory();

        $pgdasDef = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'PGDAS_D'],
            [
                'name' => 'PGDAS-D',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        $defisDef = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'DEFIS'],
            [
                'name' => 'DEFIS',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'DEFIS',
                'is_active' => true,
                'sort_order' => 20,
            ],
        );
        $dasnDef = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'DASN_SIMEI'],
            [
                'name' => 'DASN-SIMEI',
                'system_code' => 'INTEGRA_MEI',
                'service_code' => 'DASN_SIMEI',
                'is_active' => true,
                'sort_order' => 30,
            ],
        );
        $mitDef = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'MIT'],
            [
                'name' => 'MIT',
                'system_code' => 'INTEGRA_DCTFWEB',
                'service_code' => 'MIT',
                'is_active' => true,
                'sort_order' => 50,
            ],
        );

        $pgdasClient = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);
        $defisClient = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);
        $dasnClient = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);
        $mitClient = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);

        TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $pgdasClient->id,
            'obligation_definition_id' => $pgdasDef->id,
            'period_key' => '2026-06',
            'period_year' => 2026,
            'period_month' => 6,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
        TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $defisClient->id,
            'obligation_definition_id' => $defisDef->id,
            'period_key' => '2025',
            'period_year' => 2025,
            'period_month' => null,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);

        TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $dasnClient->id,
            'obligation_definition_id' => $dasnDef->id,
            'period_key' => '2025',
            'period_year' => 2025,
            'period_month' => null,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
        TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $mitClient->id,
            'obligation_definition_id' => $mitDef->id,
            'period_key' => '2026-06',
            'period_year' => 2026,
            'period_month' => 6,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);

        return [$tenant, $pgdasClient, $defisClient, $dasnClient, $mitClient];
    }

    private function ensureDeclarationsCategory(): void
    {
        FiscalCategory::query()->firstOrCreate(
            ['code' => 'DECLARACOES'],
            [
                'name' => 'Declarações',
                'module_key' => 'declarations',
                'default_coverage' => FiscalCoverage::Partial,
                'default_mutability' => FiscalMutability::ReadOnly,
                'system_code' => 'INTEGRA_CONTADOR',
                'service_code' => 'DECLARACAO',
                'is_active' => true,
                'sort_order' => 80,
            ],
        );
    }
}
