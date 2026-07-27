<?php

namespace Tests\Feature;

use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalSituation;
use App\Enums\TaxGuidePaymentStatus;
use App\Enums\TaxObligationApplicability;
use App\Models\Client;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebDeclaration;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\Tenant;
use App\Services\Fiscal\Declarations\DeclarationDctfwebEnrichmentService;
use App\Services\Fiscal\Guides\ClientGuidesQueryService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDetailDctfwebHubWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_declaration_enrichment_marks_receipt_up_to_date(): void
    {
        [$tenant, $client, $projection] = $this->seedProjection();

        DctfwebDeclaration::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'period_key' => '2026-06',
            'category' => DctfwebCategory::default()->value,
            'declaration_type' => 'ORIGINAL',
            'transmission_status' => 'TRANSMITTED',
            'situation' => FiscalSituation::UpToDate,
            'declaration_state' => DctfwebDeclarationState::Current,
            'receipt_number' => 'REC-2026-06-001',
            'last_productive_consulted_at' => CarbonImmutable::parse('2026-07-10'),
        ]);

        $rows = app(DeclarationDctfwebEnrichmentService::class)->enrichFromProjections(
            $tenant,
            [$projection->fresh(['obligation'])],
            true,
            (int) $client->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('REC-2026-06-001', $rows[0]['declaration_number']);
        $this->assertSame(FiscalSituation::UpToDate->value, $rows[0]['delivery_status']);
        $this->assertSame('DCTFWEB_CONSULT', $rows[0]['source']);
    }

    public function test_declaration_list_includes_synthetic_without_projection(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);

        DctfwebDeclaration::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'period_key' => '2026-05',
            'category' => DctfwebCategory::default()->value,
            'declaration_type' => 'ORIGINAL',
            'transmission_status' => 'TRANSMITTED',
            'situation' => FiscalSituation::UpToDate,
            'declaration_state' => DctfwebDeclarationState::Current,
            'receipt_number' => 'REC-2026-05-009',
            'last_productive_consulted_at' => CarbonImmutable::parse('2026-06-12'),
        ]);

        $rows = app(DeclarationDctfwebEnrichmentService::class)->enrichPublicRows(
            $tenant,
            [],
            (int) $client->id,
        );

        $this->assertCount(1, $rows);
        $this->assertSame('DCTFWEB', $rows[0]['obligation_code']);
        $this->assertSame('REC-2026-05-009', $rows[0]['declaration_number']);
        $this->assertSame('DCTFWEB_CONSULT', $rows[0]['source']);
        $this->assertStringStartsWith('dctfweb-decl-', (string) $rows[0]['id']);
    }

    public function test_client_guides_include_darf_document(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);

        $declaration = DctfwebDeclaration::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'period_key' => '2026-06',
            'category' => DctfwebCategory::default()->value,
            'declaration_type' => 'ORIGINAL',
            'situation' => FiscalSituation::UpToDate,
            'declaration_state' => DctfwebDeclarationState::Current,
            'receipt_number' => 'REC-1',
        ]);

        DctfwebDarfDocument::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'declaration_id' => $declaration->id,
            'document_number' => 'DARF-998877',
            'amount' => 150.55,
            'issued_at' => CarbonImmutable::parse('2026-07-11'),
            'due_at' => CarbonImmutable::parse('2026-07-20'),
            'payment_status' => FiscalPaymentStatus::Unpaid,
            'content_sha256' => str_repeat('a', 64),
        ]);

        $page = app(ClientGuidesQueryService::class)->paginate($tenant, (int) $client->id, 20)['page'];
        $this->assertSame(1, $page->total());
        $row = $page->items()[0];
        $this->assertSame('DARF-998877', $row['identifier_code']);
        $this->assertSame('DCTFWEB_DARF', $row['source']);
        $this->assertSame(TaxGuidePaymentStatus::NotConfirmed->value, $row['payment_status']);
        $this->assertSame(15055, $row['amount_cents']);
        $this->assertSame('2026-06', $row['competence_period_key']);
    }

    public function test_receipt_only_does_not_invent_darf_guide(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);

        DctfwebDeclaration::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'period_key' => '2026-06',
            'category' => DctfwebCategory::default()->value,
            'situation' => FiscalSituation::UpToDate,
            'declaration_state' => DctfwebDeclarationState::Current,
            'receipt_number' => 'REC-ONLY',
        ]);

        $page = app(ClientGuidesQueryService::class)->paginate($tenant, (int) $client->id, 20)['page'];
        $this->assertSame(0, $page->total());
    }

    /**
     * @return array{0: Tenant, 1: Client, 2: TaxObligationProjection}
     */
    private function seedProjection(): array
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create([
            'is_active' => true,
        ]);

        $def = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'DCTFWEB'],
            [
                'name' => 'DCTFWeb',
                'system_code' => 'INTEGRA_DCTFWEB',
                'service_code' => 'DCTFWEB',
                'is_active' => true,
                'sort_order' => 40,
            ],
        );

        $projection = TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $def->id,
            'period_key' => '2026-06',
            'period_year' => 2026,
            'period_month' => 6,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);

        return [$tenant, $client, $projection];
    }
}
