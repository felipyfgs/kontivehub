<?php

namespace Tests\Feature;

use App\Enums\FiscalSituation;
use App\Enums\PgdasdOperationKind;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxObligationApplicability;
use App\Models\Client;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\TaxGuide;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\Declarations\DeclarationPgdasdEnrichmentService;
use App\Services\Fiscal\Guides\ClientGuidesQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDetailPgdasdHubWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_declaration_enrichment_marks_consulted_declaration_up_to_date(): void
    {
        [$office, $client, $withDecl, $withoutDecl] = $this->seedProjections();

        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $withDecl->id,
            'kind' => PgdasdOperationKind::Declaration,
            'period_key' => '2026-06',
            'logical_key' => 'decl:2026-06:5001',
            'raw_operation_type' => 'Declaração Original',
            'normalized_operation_type' => 'ORIGINAL',
            'declaration_number' => '50029654202606001',
            'transmitted_at' => CarbonImmutable::parse('2026-07-07'),
            'first_seen_at' => CarbonImmutable::parse('2026-07-07'),
            'last_seen_at' => CarbonImmutable::parse('2026-07-07'),
        ]);

        $rows = app(DeclarationPgdasdEnrichmentService::class)->enrichPublicList(
            $office,
            [$withDecl->fresh(['obligation']), $withoutDecl->fresh(['obligation'])],
        );

        $enriched = collect($rows)->firstWhere('period_key', '2026-06');
        $pending = collect($rows)->firstWhere('period_key', '2026-05');

        $this->assertNotNull($enriched);
        $this->assertSame('50029654202606001', $enriched['declaration_number']);
        $this->assertSame(FiscalSituation::UpToDate->value, $enriched['delivery_status']);
        $this->assertSame(FiscalSituation::UpToDate->value, $enriched['situation']);
        $this->assertSame('PGDASD_CONSULT', $enriched['source']);

        $this->assertNotNull($pending);
        $this->assertArrayNotHasKey('declaration_number', $pending);
        $this->assertSame(FiscalSituation::Pending->value, $pending['delivery_status']);
    }

    public function test_client_guides_list_includes_das_when_tax_guides_empty(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makePgdasProjection($office, $client, '2026-06', 6);

        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => '2026-06',
            'logical_key' => 'das:2026-06:07202619183811980',
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => '07202619183811980',
            'issued_at' => CarbonImmutable::parse('2026-07-10'),
            'payment_located' => false,
            'first_seen_at' => CarbonImmutable::parse('2026-07-10'),
            'last_seen_at' => CarbonImmutable::parse('2026-07-10'),
        ]);

        $page = app(ClientGuidesQueryService::class)->paginate($office, (int) $client->id, 20)['page'];
        $this->assertSame(1, $page->total());
        $row = $page->items()[0];
        $this->assertSame('07202619183811980', $row['identifier_code']);
        $this->assertSame('2026-06', $row['competence_period_key']);
        $this->assertSame(TaxGuidePaymentStatus::NotConfirmed->value, $row['payment_status']);
        $this->assertSame('PGDASD_CONSULT', $row['source']);
    }

    public function test_client_guides_dedupes_das_already_in_tax_guides(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makePgdasProjection($office, $client, '2026-06', 6);

        $das = '07202619183811980';

        TaxGuide::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'GERAR_DAS',
            'competence_period_key' => '2026-06',
            'logical_key' => 'guide:'.$das,
            'payment_status' => TaxGuidePaymentStatus::Unknown,
            'identifier_code' => $das,
            'amount_cents' => 12345,
        ]);

        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => '2026-06',
            'logical_key' => 'das:2026-06:'.$das,
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => $das,
            'issued_at' => CarbonImmutable::parse('2026-07-10'),
            'payment_located' => false,
            'first_seen_at' => CarbonImmutable::parse('2026-07-10'),
            'last_seen_at' => CarbonImmutable::parse('2026-07-10'),
        ]);

        $page = app(ClientGuidesQueryService::class)->paginate($office, (int) $client->id, 20)['page'];
        $this->assertSame(1, $page->total());
        $row = $page->items()[0];
        $this->assertSame('TAX_GUIDE', $row['source']);
        $this->assertSame($das, $row['identifier_code']);
    }

    public function test_office_wide_list_includes_das_without_tax_guides(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $projection = $this->makePgdasProjection($office, $client, '2026-06', 6);

        PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => '2026-06',
            'logical_key' => 'das:2026-06:07202619183811980',
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => '07202619183811980',
            'issued_at' => CarbonImmutable::parse('2026-07-10'),
            'payment_located' => false,
            'first_seen_at' => CarbonImmutable::parse('2026-07-10'),
            'last_seen_at' => CarbonImmutable::parse('2026-07-10'),
        ]);

        $result = app(ClientGuidesQueryService::class)->paginate($office, null, 20);
        $page = $result['page'];
        $this->assertSame(1, $page->total());
        $this->assertSame(1, $result['payment_counters'][TaxGuidePaymentStatus::NotConfirmed->value]);
        $this->assertSame(0, $result['payment_counters'][TaxGuidePaymentStatus::Unknown->value]);
        $row = $page->items()[0];
        $this->assertSame('PGDASD_CONSULT', $row['source']);
        $this->assertSame('07202619183811980', $row['identifier_code']);
    }

    private function makePgdasProjection(Office $office, Client $client, string $periodKey, int $month): TaxObligationProjection
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

    /**
     * @return array{0: Office, 1: Client, 2: TaxObligationProjection, 3: TaxObligationProjection}
     */
    private function seedProjections(): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);

        $withDecl = $this->makePgdasProjection($office, $client, '2026-06', 6);
        $withoutDecl = $this->makePgdasProjection($office, $client, '2026-05', 5);

        return [$office, $client, $withDecl, $withoutDecl];
    }
}
