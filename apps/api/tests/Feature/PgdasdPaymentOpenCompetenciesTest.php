<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalProfile;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\OfficeRole;
use App\Enums\PgdasdOperationKind;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\TaxGuide;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\User;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PgdasdPaymentOpenCompetenciesTest extends TestCase
{
    use RefreshDatabase;

    public function test_portfolio_details_aggregates_unpaid_competencies_with_optional_amounts(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);

        $projectionJun = $this->makeProjection($office, $client, '2026-06', 6);
        $projectionMay = $this->makeProjection($office, $client, '2026-05', 5);

        $dasJun = '07202619183811980';
        $dasMayA = '07202619183811981';
        $dasMayB = '07202619183811982';

        $this->createDas($office, $client, $projectionJun, '2026-06', $dasJun, false);
        $this->createDas($office, $client, $projectionMay, '2026-05', $dasMayA, false);
        $this->createDas($office, $client, $projectionMay, '2026-05', $dasMayB, false);
        $this->createDas($office, $client, $projectionMay, '2026-04', '07202619183811983', true);

        TaxGuide::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => '2026-05',
            'logical_key' => 'guide:'.$dasMayA,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'identifier_code' => $dasMayA,
            'amount_cents' => 10000,
        ]);
        TaxGuide::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => '2026-05',
            'logical_key' => 'guide:'.$dasMayB,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'identifier_code' => $dasMayB,
            'amount_cents' => 5000,
        ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $open = $details[(int) $client->id]['payment_open_competencies'] ?? null;
        $this->assertIsArray($open);
        $this->assertSame(
            [
                ['period_key' => '2026-06', 'amount_cents' => null],
                ['period_key' => '2026-05', 'amount_cents' => 10000],
            ],
            $open,
        );
    }

    public function test_open_competency_uses_max_not_sum_of_reissued_das(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);

        $dasNumbers = [
            '07202619183811980',
            '07202619183811981',
            '07202619183811982',
            '07202619183811983',
            '07202619183811984',
        ];

        foreach ($dasNumbers as $dasNumber) {
            $this->createDas($office, $client, $projection, '2026-06', $dasNumber, false);
            TaxGuide::query()->withoutGlobalScopes()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'operation_code' => 'GERAR_DAS',
                'competence_period_key' => '2026-06',
                'logical_key' => 'guide:'.$dasNumber,
                'payment_status' => TaxGuidePaymentStatus::Unknown,
                'identifier_code' => $dasNumber,
                'amount_cents' => 14125,
            ]);
        }

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => 14125]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
    }

    public function test_period_with_any_paid_das_is_excluded_from_open_competencies(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);

        $projectionMay = $this->makeProjection($office, $client, '2026-05', 5);
        $projectionJun = $this->makeProjection($office, $client, '2026-06', 6);

        $this->createDas($office, $client, $projectionMay, '2026-05', '07202619183811981', true);
        $this->createDas($office, $client, $projectionMay, '2026-05', '07202619183811982', false);
        $this->createDas($office, $client, $projectionJun, '2026-06', '07202619183811980', false);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => null]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
    }

    public function test_mixed_amounts_in_same_period_yield_null_amount(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);

        $this->createDas($office, $client, $projection, '2026-06', '07202619183811980', false);
        $this->createDas($office, $client, $projection, '2026-06', '07202619183811981', false);

        TaxGuide::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => '2026-06',
            'logical_key' => 'guide:07202619183811980',
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'identifier_code' => '07202619183811980',
            'amount_cents' => 12345,
        ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $open = $details[(int) $client->id]['payment_open_competencies'];
        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => null]],
            $open,
        );
    }

    public function test_fallback_uses_gerar_das_snapshot_amount_when_guide_missing(): void
    {
        Http::fake();

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);
        $dasNumber = '07202619183811980';
        $this->createDas($office, $client, $projection, '2026-06', $dasNumber, false);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'operation_key' => 'pgdasd.gerardas',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'gerar-das-snapshot:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::Attention,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'situation' => FiscalSituation::Attention,
            'coverage' => FiscalCoverage::Partial,
            'version' => 1,
            'is_current' => true,
            'normalized' => [
                'dto' => 'das_guide',
                'document_number' => $dasNumber,
                'amount' => 199.9,
            ],
            'observed_at' => now(),
            'created_at' => now(),
        ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => 19990]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
        Http::assertNothingSent();
    }

    public function test_fallback_uses_gerar_das_evidence_when_snapshot_incomplete(): void
    {
        Http::fake();
        $this->app->instance(SecureObjectStore::class, new class implements SecureObjectStore
        {
            /** @var array<string, string> */
            private array $objects = [];

            private int $sequence = 0;

            public function put(string $plaintext, array $metadata = []): string
            {
                $this->sequence++;
                $id = '01J'.str_pad((string) $this->sequence, 23, '0', STR_PAD_LEFT);
                $this->objects[$id] = $plaintext;

                return $id;
            }

            public function get(string $objectId, array $metadata = []): string
            {
                return $this->objects[$objectId] ?? throw new \RuntimeException('missing');
            }

            public function delete(string $objectId): void
            {
                unset($this->objects[$objectId]);
            }

            public function exists(string $objectId): bool
            {
                return isset($this->objects[$objectId]);
            }
        });

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-05', 5);
        $dasNumber = '07202619183811991';
        $this->createDas($office, $client, $projection, '2026-05', $dasNumber, false);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'operation_key' => 'pgdasd.gerardas',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'gerar-das-evidence:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::Attention,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: json_encode([
                'status' => 200,
                'dados' => [
                    'numeroDocumento' => $dasNumber,
                    'total' => 87.65,
                ],
            ], JSON_THROW_ON_ERROR),
            contentType: 'application/json',
            source: 'SERPRO',
        );

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-05', 'amount_cents' => 8765]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
        Http::assertNothingSent();
    }

    public function test_portfolio_uses_persisted_operation_amount_cents(): void
    {
        Http::fake();

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);
        $dasNumber = '07202619183811980';
        $this->createDas($office, $client, $projection, '2026-06', $dasNumber, false);

        PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('das_number', $dasNumber)
            ->update([
                'amount_cents' => 47692,
                'amount_source' => 'EXTRATO_PARSE',
                'amount_parser_version' => 'pgdasd-extrato-das-amount-v1',
                'amount_resolved_at' => now(),
            ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => 47692]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
        Http::assertNothingSent();
    }

    public function test_tax_guides_win_over_gerar_das_snapshot(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);
        $dasNumber = '07202619183811980';
        $this->createDas($office, $client, $projection, '2026-06', $dasNumber, false);

        TaxGuide::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => '2026-06',
            'logical_key' => 'guide:'.$dasNumber,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'identifier_code' => $dasNumber,
            'amount_cents' => 1111,
        ]);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'gerar-das-guide-wins:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::Attention,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'situation' => FiscalSituation::Attention,
            'coverage' => FiscalCoverage::Partial,
            'version' => 1,
            'is_current' => true,
            'normalized' => [
                'document_number' => $dasNumber,
                'amount' => 999.99,
            ],
            'observed_at' => now(),
            'created_at' => now(),
        ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => 1111]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
    }

    public function test_portfolio_lists_only_fresh_complete_negative_pagtoweb_coverage_without_http(): void
    {
        Http::fake();
        config()->set('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds', 86_400);

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $paidProjection = $this->makeProjection($office, $client, '2026-04', 4);
        $negativeProjection = $this->makeProjection($office, $client, '2026-05', 5);
        $uncoveredProjection = $this->makeProjection($office, $client, '2026-06', 6);

        $this->createDas($office, $client, $paidProjection, '2026-04', '07202619183811001', true);
        $this->createDas($office, $client, $negativeProjection, '2026-05', '07202619183811002', false);
        $this->createDas($office, $client, $uncoveredProjection, '2026-06', '07202619183811003', null);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-05', 'amount_cents' => null]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
        Http::assertNothingSent();
    }

    public function test_pagtoweb_amount_wins_over_local_amount_fallbacks(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makeProjection($office, $client, '2026-06', 6);
        $dasNumber = '07202619183811980';
        $this->createDas($office, $client, $projection, '2026-06', $dasNumber, false);

        PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('das_number', $dasNumber)
            ->update([
                'pagtoweb_amount_cents' => 32100,
                'amount_cents' => 99900,
            ]);

        $details = app(PgdasdMonitoringQueryService::class)
            ->portfolioDetails($office, [(int) $client->id]);

        $this->assertSame(
            [['period_key' => '2026-06', 'amount_cents' => 32100]],
            $details[(int) $client->id]['payment_open_competencies'],
        );
    }

    public function test_portfolio_clients_payload_includes_normalized_cnpj(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'root_cnpj' => '26461528',
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        Establishment::factory()->forClient($client)->create([
            'cnpj' => '26461528000151',
            'is_matrix' => true,
        ]);

        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $response = $this->getJson('/api/v1/fiscal/modules/simples_mei/clients?submodule=PGDASD&per_page=50')
            ->assertOk();

        $row = collect($response->json('data'))->firstWhere('client_id', $client->id);
        $this->assertNotNull($row);
        $this->assertSame('26461528000151', $row['cnpj']);
        $this->assertArrayHasKey('cnpj_masked', $row);
        $this->assertNotSame('26461528000151', $row['cnpj_masked']);
    }

    private function makeProjection(
        Office $office,
        Client $client,
        string $periodKey,
        int $month,
    ): TaxObligationProjection {
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

    private function createDas(
        Office $office,
        Client $client,
        TaxObligationProjection $projection,
        string $periodKey,
        string $dasNumber,
        ?bool $paymentLocated,
    ): void {
        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => $periodKey,
            'logical_key' => 'das:'.$periodKey.':'.$dasNumber,
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => $dasNumber,
            'issued_at' => CarbonImmutable::parse($periodKey.'-15'),
            'payment_located' => $paymentLocated,
            'pagtoweb_payment_status' => match ($paymentLocated) {
                true => 'PAID',
                false => 'NOT_FOUND',
                null => null,
            },
            'pagtoweb_verified_at' => $paymentLocated === null ? null : CarbonImmutable::now(),
            'first_seen_at' => CarbonImmutable::parse($periodKey.'-15'),
            'last_seen_at' => CarbonImmutable::parse($periodKey.'-15'),
        ]);
    }
}
