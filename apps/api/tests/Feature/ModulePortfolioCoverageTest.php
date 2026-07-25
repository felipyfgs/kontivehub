<?php

namespace Tests\Feature;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulePortfolioCoverageTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_rows_and_filter_share_partial_aggregation_for_mixed_dimensions(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $this->category('DCTFWEB_FULL', 'INTEGRA_DCTFWEB', FiscalCoverage::Full, 1);
        $this->category('MIT_UNSUPPORTED', 'INTEGRA_MIT', FiscalCoverage::Unsupported, 2);
        $this->snapshot($office, $client, 'INTEGRA_DCTFWEB', FiscalCoverage::Full);
        $this->snapshot($office, $client, 'INTEGRA_MIT', FiscalCoverage::Unsupported);
        $portfolio = app(ModulePortfolioQueryService::class);

        $overview = $portfolio->overview(
            $office,
            FiscalModuleKey::Dctfweb,
            ModulePortfolioFilters::fromRequest([]),
        );
        $this->assertSame(FiscalCoverage::Partial->value, $overview->coverage);

        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::Dctfweb,
            ModulePortfolioFilters::fromRequest([]),
        );
        $this->assertSame(1, $page->total());
        $this->assertSame(FiscalCoverage::Partial->value, $page->items()[0]->coverage);
        $this->assertFalse($page->items()[0]->document?->available ?? true);
        $this->assertNull($page->items()[0]->document?->href);

        $partial = $portfolio->clients(
            $office,
            FiscalModuleKey::Dctfweb,
            ModulePortfolioFilters::fromRequest(['coverage' => 'PARTIAL']),
        );
        $full = $portfolio->clients(
            $office,
            FiscalModuleKey::Dctfweb,
            ModulePortfolioFilters::fromRequest(['coverage' => 'FULL']),
        );
        $this->assertSame(1, $partial->total());
        $this->assertSame(0, $full->total());
    }

    private function category(
        string $code,
        string $systemCode,
        FiscalCoverage $coverage,
        int $sortOrder,
    ): void {
        FiscalCategory::query()->create([
            'code' => $code,
            'name' => $code,
            'module_key' => 'dctfweb_mit',
            'default_coverage' => $coverage,
            'default_mutability' => FiscalMutability::ReadOnly,
            'system_code' => $systemCode,
            'service_code' => str_replace('INTEGRA_', '', $systemCode),
            'is_active' => true,
            'sort_order' => $sortOrder,
        ]);
    }

    private function snapshot(
        Office $office,
        Client $client,
        string $systemCode,
        FiscalCoverage $coverage,
    ): void {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => $systemCode,
            'service_code' => str_replace('INTEGRA_', '', $systemCode),
            'operation_code' => 'CONSULTAR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'portfolio-coverage:'.$systemCode,
            'status' => FiscalRunStatus::Completed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => $coverage,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
        FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => $systemCode,
            'service_code' => str_replace('INTEGRA_', '', $systemCode),
            'operation_code' => 'CONSULTAR',
            'situation' => FiscalSituation::Unknown,
            'coverage' => $coverage,
            'version' => 1,
            'is_current' => true,
            'normalized' => [],
            'observed_at' => now(),
            'created_at' => now(),
        ]);
    }
}
