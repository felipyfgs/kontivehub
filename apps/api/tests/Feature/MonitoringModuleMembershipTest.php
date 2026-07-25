<?php

namespace Tests\Feature;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalModuleKey;
use App\Enums\OfficeRole;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use App\Services\FiscalMonitoring\MonitoringModuleMembershipService;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MonitoringModuleMembershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_exclude_removes_client_from_portfolio_and_include_restores(): void
    {
        [$office, $sn, $mei] = $this->seedMixed();
        $membership = app(MonitoringModuleMembershipService::class);
        $portfolio = app(ModulePortfolioQueryService::class);

        $membership->exclude($office, FiscalModuleKey::SimplesMei, [$sn->id], 'PGDASD');

        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );
        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertNotContains($sn->id, $ids);
        $this->assertSame(0, $page->total());

        $membership->include($office, FiscalModuleKey::SimplesMei, [$sn->id], 'PGDASD');

        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );
        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($sn->id, $ids);

        // MEI tab untouched by PGDASD exclusion
        $membership->exclude($office, FiscalModuleKey::SimplesMei, [$sn->id], 'PGDASD');
        $meiPage = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGMEI', 'per_page' => 50]),
        );
        $this->assertContains($mei->id, collect($meiPage->items())->map(fn ($r) => $r->clientId)->all());
    }

    public function test_include_rejects_client_outside_regime(): void
    {
        [$office, $sn, $mei] = $this->seedMixed();
        $membership = app(MonitoringModuleMembershipService::class);

        $result = $membership->include(
            $office,
            FiscalModuleKey::SimplesMei,
            [$mei->id],
            'PGDASD',
        );

        $this->assertSame(0, $result['included']);
        $this->assertNotEmpty($result['errors']);
        $this->assertSame($mei->id, $result['errors'][0]['client_id']);
    }

    public function test_http_exclude_is_tenant_scoped(): void
    {
        [$office, $sn] = $this->seedMixed();
        $otherOffice = Office::factory()->create();
        $otherSn = Client::factory()->for($otherOffice)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);

        $actor = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();
        $this->assertSame($office->id, app(CurrentOffice::class)->resolve($actor)?->id);

        $this->postJson('/api/v1/fiscal/monitoring/membership/exclude', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$sn->id, $otherSn->id],
        ])->assertOk();

        $this->assertDatabaseHas('office_monitoring_module_exclusions', [
            'office_id' => $office->id,
            'client_id' => $sn->id,
            'module_key' => 'simples_mei',
            'submodule' => 'PGDASD',
        ]);
        $this->assertDatabaseMissing('office_monitoring_module_exclusions', [
            'client_id' => $otherSn->id,
        ]);
    }

    public function test_http_list_include_exclude_roundtrip_for_pgdasd(): void
    {
        [$office, $sn] = $this->seedMixed();
        $actor = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();

        $this->postJson('/api/v1/fiscal/monitoring/membership/exclude', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$sn->id],
        ])->assertOk();

        $listed = $this->getJson('/api/v1/fiscal/monitoring/membership?module=simples_mei&submodule=PGDASD')
            ->assertOk()
            ->json('data');
        $this->assertContains($sn->id, collect($listed)->pluck('client_id')->all());

        $this->postJson('/api/v1/fiscal/monitoring/membership/include', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$sn->id],
        ])->assertOk();

        $listedAfter = $this->getJson('/api/v1/fiscal/monitoring/membership?module=simples_mei&submodule=PGDASD')
            ->assertOk()
            ->json('data');
        $this->assertNotContains($sn->id, collect($listedAfter)->pluck('client_id')->all());

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );
        $this->assertContains($sn->id, collect($page->items())->map(fn ($r) => $r->clientId)->all());
    }

    public function test_http_include_rejects_mei_on_pgdasd(): void
    {
        [$office, $sn, $mei] = $this->seedMixed();
        $actor = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        Sanctum::actingAs($actor);
        app(CurrentOffice::class)->clear();

        $this->postJson('/api/v1/fiscal/monitoring/membership/include', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$mei->id],
        ])->assertStatus(422);
    }

    public function test_viewer_cannot_mutate_membership(): void
    {
        [$office, $sn] = $this->seedMixed();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        Sanctum::actingAs($viewer);
        app(CurrentOffice::class)->clear();

        $this->postJson('/api/v1/fiscal/monitoring/membership/exclude', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$sn->id],
        ])->assertForbidden();

        $this->postJson('/api/v1/fiscal/monitoring/membership/include', [
            'module' => 'simples_mei',
            'submodule' => 'PGDASD',
            'client_ids' => [$sn->id],
        ])->assertForbidden();

        $this->getJson('/api/v1/fiscal/monitoring/membership?module=simples_mei&submodule=PGDASD')
            ->assertOk();
    }

    /**
     * @return array{0: Office, 1: Client, 2: Client}
     */
    private function seedMixed(): array
    {
        $office = Office::factory()->create();
        $sn = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $mei = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);

        return [$office, $sn, $mei];
    }
}
