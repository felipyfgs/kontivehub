<?php

namespace Tests\Feature;

use App\DTO\Fiscal\Module\ModulePortfolioFilters;
use App\Enums\FiscalModuleKey;
use App\Enums\FiscalSituation;
use App\Enums\PgdasdOperationKind;
use App\Enums\PgdasdRbt12Status;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientProcuracaoSync;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\PgdasdRbt12Projection;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPeriod;
use App\Services\FiscalMonitoring\ModulePortfolio\ModulePortfolioQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ModulePortfolioSimplesMeiSubmoduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgdasd_submodule_lists_only_simples_nacional_clients(): void
    {
        [$office, $sn, $mei, $other] = $this->seedMixedRegimeClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );

        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($sn->id, $ids);
        $this->assertNotContains($mei->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(1, $page->total());
    }

    public function test_pgmei_submodule_lists_only_mei_clients(): void
    {
        [$office, $sn, $mei, $other] = $this->seedMixedRegimeClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGMEI', 'per_page' => 50]),
        );

        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($mei->id, $ids);
        $this->assertNotContains($sn->id, $ids);
        $this->assertNotContains($other->id, $ids);
        $this->assertSame(1, $page->total());
    }

    public function test_overview_counters_follow_regime_scope(): void
    {
        [$office] = $this->seedMixedRegimeClients();
        $portfolio = app(ModulePortfolioQueryService::class);

        $pgdasd = $portfolio->overview(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD']),
        );
        $pgmei = $portfolio->overview(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGMEI']),
        );

        $this->assertSame(1, $pgdasd->totalClients);
        $this->assertSame(1, $pgmei->totalClients);
    }

    public function test_does_not_include_matching_regime_from_other_office(): void
    {
        [$office] = $this->seedMixedRegimeClients();
        $otherOffice = Office::factory()->create();
        Client::factory()->for($otherOffice)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );

        $this->assertSame(1, $page->total());
    }

    public function test_legacy_simples_alias_matches_pgdasd_scope(): void
    {
        $office = Office::factory()->create();
        $legacy = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => 'SIMPLES',
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );

        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($legacy->id, $ids);
    }

    public function test_pgdasd_detail_exposes_missing_procuracao_status(): void
    {
        [$office, $sn] = $this->seedMixedRegimeClients();
        ClientProcuracaoSync::factory()->forClient($sn)->missing()->create();

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest(['submodule' => 'PGDASD', 'per_page' => 50]),
        );

        $row = collect($page->items())->first(
            static fn ($item) => (int) $item->clientId === (int) $sn->id,
        );
        $this->assertNotNull($row);
        $detail = $row->detail;
        $this->assertIsArray($detail);
        $this->assertSame('missing', $detail['procuracao_status'] ?? null);
    }

    public function test_pgdasd_send_status_filters_sent_and_not_sent(): void
    {
        $office = Office::factory()->create();
        $sent = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        $notSent = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);

        ClientCommunicationDispatch::query()->create([
            'office_id' => $office->id,
            'client_id' => $sent->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'channel' => 'EMAIL',
            'status' => 'QUEUED',
            'recipient_masked' => 'a***@example.com',
            'recipient_hash' => hash('sha256', 'a@example.com'),
            'idempotency_key' => 'test-send-filter-'.$sent->id,
            'queued_at' => now(),
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);

        $sentPage = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest([
                'submodule' => 'PGDASD',
                'send_status' => 'sent',
                'per_page' => 50,
            ]),
        );
        $sentIds = collect($sentPage->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($sent->id, $sentIds);
        $this->assertNotContains($notSent->id, $sentIds);

        $notSentPage = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest([
                'submodule' => 'PGDASD',
                'send_status' => 'not_sent',
                'per_page' => 50,
            ]),
        );
        $notSentIds = collect($notSentPage->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($notSent->id, $notSentIds);
        $this->assertNotContains($sent->id, $notSentIds);
    }

    public function test_pgmei_ignores_send_status_filter(): void
    {
        $office = Office::factory()->create();
        $mei = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::Mei->value,
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest([
                'submodule' => 'PGMEI',
                'send_status' => 'sent',
                'per_page' => 50,
            ]),
        );

        $ids = collect($page->items())->map(fn ($row) => $row->clientId)->all();
        $this->assertContains($mei->id, $ids);
        $this->assertSame(1, $page->total());
    }

    public function test_pgdasd_rbt12_sort_uses_display_period_not_latest_id(): void
    {
        $office = Office::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $expectedKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, 'America/Sao_Paulo'));
        $otherKey = '2025-01';

        $lowDisplay = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
            'legal_name' => 'AAA Low Display',
        ]);
        $highDisplay = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
            'legal_name' => 'BBB High Display',
        ]);

        $projLowExpected = $this->makePgdasProjection($office, $lowDisplay, $expectedKey);
        $projLowOther = $this->makePgdasProjection($office, $lowDisplay, $otherKey);
        $projHighExpected = $this->makePgdasProjection($office, $highDisplay, $expectedKey);

        // Declaração no PA esperado → display = expected; RBT12 alto em outro período não deve vencer.
        $this->makeDeclaration($office, $lowDisplay, $projLowExpected, 'decl-low-expected');
        $this->makeDeclaration($office, $highDisplay, $projHighExpected, 'decl-high-expected');

        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $lowDisplay->id,
            'projection_id' => $projLowExpected->id,
            'source_reference_key' => 'rbt-low-expected',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 1_000_00,
        ]);
        // Maior id, período irrelevante — sort antigo por id DESC escolheria este valor.
        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $lowDisplay->id,
            'projection_id' => $projLowOther->id,
            'source_reference_key' => 'rbt-low-other',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 9_999_99,
        ]);
        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $highDisplay->id,
            'projection_id' => $projHighExpected->id,
            'source_reference_key' => 'rbt-high-expected',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 2_000_00,
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest([
                'submodule' => 'PGDASD',
                'sort' => 'rbt12',
                'sort_direction' => 'desc',
                'per_page' => 50,
            ]),
        );

        $ids = collect($page->items())->map(fn ($row) => (int) $row->clientId)->all();
        $this->assertSame([(int) $highDisplay->id, (int) $lowDisplay->id], $ids);

        $lowRow = collect($page->items())->first(
            static fn ($item) => (int) $item->clientId === (int) $lowDisplay->id,
        );
        $this->assertNotNull($lowRow);
        $this->assertSame(1_000_00, $lowRow->detail['rbt12']['total_cents'] ?? null);
    }

    public function test_pgdasd_rbt12_sort_follows_declaration_display_period_not_expected_pa(): void
    {
        $office = Office::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $expectedKey = PgdasdPeriod::toPeriodKey(PgdasdPeriod::expectedPa(null, 'America/Sao_Paulo'));
        $otherKey = '2025-01';

        $displayOther = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
            'legal_name' => 'AAA Display Other',
        ]);
        $displayExpected = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
            'legal_name' => 'BBB Display Expected',
        ]);

        $projOtherExpected = $this->makePgdasProjection($office, $displayOther, $expectedKey);
        $projOtherOther = $this->makePgdasProjection($office, $displayOther, $otherKey);
        $projExpected = $this->makePgdasProjection($office, $displayExpected, $expectedKey);

        // Sem declaração no PA esperado → display = otherKey.
        $this->makeDeclaration($office, $displayOther, $projOtherOther, 'decl-other');
        $this->makeDeclaration($office, $displayExpected, $projExpected, 'decl-expected');

        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $displayOther->id,
            'projection_id' => $projOtherExpected->id,
            'source_reference_key' => 'rbt-other-expected-low',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 1_000_00,
        ]);
        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $displayOther->id,
            'projection_id' => $projOtherOther->id,
            'source_reference_key' => 'rbt-other-display-high',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 9_000_00,
        ]);
        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $displayExpected->id,
            'projection_id' => $projExpected->id,
            'source_reference_key' => 'rbt-expected-mid',
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 5_000_00,
        ]);

        $portfolio = app(ModulePortfolioQueryService::class);
        $page = $portfolio->clients(
            $office,
            FiscalModuleKey::SimplesMei,
            ModulePortfolioFilters::fromRequest([
                'submodule' => 'PGDASD',
                'sort' => 'rbt12',
                'sort_direction' => 'desc',
                'per_page' => 50,
            ]),
        );

        $ids = collect($page->items())->map(fn ($row) => (int) $row->clientId)->all();
        $this->assertSame([(int) $displayOther->id, (int) $displayExpected->id], $ids);

        $otherRow = collect($page->items())->first(
            static fn ($item) => (int) $item->clientId === (int) $displayOther->id,
        );
        $this->assertNotNull($otherRow);
        $this->assertSame(9_000_00, $otherRow->detail['rbt12']['total_cents'] ?? null);
    }

    private function makePgdasProjection(Office $office, Client $client, string $periodKey): TaxObligationProjection
    {
        $def = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'PGDAS_D'],
            [
                'name' => 'PGDAS-D',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        $month = (int) substr($periodKey, 5, 2);

        return TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $def->id,
            'period_key' => $periodKey,
            'period_year' => (int) substr($periodKey, 0, 4),
            'period_month' => $month,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
    }

    private function makeDeclaration(
        Office $office,
        Client $client,
        TaxObligationProjection $projection,
        string $number,
    ): PgdasdOperation {
        return PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Declaration,
            'period_key' => $projection->period_key,
            'logical_key' => 'decl:'.$projection->period_key.':'.$number,
            'raw_operation_type' => 'Declaração Original',
            'normalized_operation_type' => 'ORIGINAL',
            'declaration_number' => $number,
            'transmitted_at' => CarbonImmutable::parse('2026-07-07T11:33:52+00:00'),
            'first_seen_at' => CarbonImmutable::parse('2026-07-07'),
            'last_seen_at' => CarbonImmutable::parse('2026-07-07'),
        ]);
    }

    /**
     * @return array{0: Office, 1: Client, 2: Client, 3: Client}
     */
    private function seedMixedRegimeClients(): array
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
        $other = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::LucroPresumido->value,
        ]);

        return [$office, $sn, $mei, $other];
    }
}
