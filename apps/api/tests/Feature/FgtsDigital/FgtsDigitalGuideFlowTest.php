<?php

namespace Tests\Feature\FgtsDigital;

use App\Contracts\FgtsDigitalPortalClient;
use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Enums\FgtsDigitalGuideType;
use App\Enums\FgtsDigitalRunStatus;
use App\Enums\FiscalMutationStatus;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\User;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\Fiscal\Guides\ClientGuidesQueryService;
use App\Services\Fiscal\Guides\GuideStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FgtsDigitalGuideFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_digital.driver', 'fixture');
        config()->set('fgts_digital.kill_switch', false);
        config()->set('fgts_digital.mutations_enabled', false);
        config()->set('fgts_digital.runtime.fixtures', base_path('rpa/fgts_digital/fixtures'));
    }

    public function test_fixture_query_persists_downloadable_pdf_and_central_guide_source_with_dedupe(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $user = User::factory()->forOffice($office)->create();
        $service = app(FgtsDigitalPortalService::class);

        $first = $service->executeRun($service->createQueryRun($office, $client, $user));
        $this->assertSame(FgtsDigitalRunStatus::Succeeded, $first->status);
        $this->assertSame('DEBT-202607-0001', $first->result_sanitized['data']['debts'][0]['identifier']);
        $this->assertDatabaseHas('tax_guides', [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'FGTS_DIGITAL',
            'identifier_code' => 'GFD-202607-0001',
        ]);
        $guide = TaxGuide::query()->withoutGlobalScopes()->where('office_id', $office->id)->firstOrFail();
        $version = TaxGuideVersion::query()->withoutGlobalScopes()->where('tax_guide_id', $guide->id)->firstOrFail();
        $bytes = app(GuideStorageService::class)->readDocumentAuthorized($version, (int) $office->id);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertFalse($guide->payment_status->isOfficiallyPaid());
        $this->assertSame('2026-07-22T12:00:00+00:00', $guide->metadata['checked_at']);

        $central = app(ClientGuidesQueryService::class)->paginate($office, (int) $client->id, 20)['page'];
        $this->assertSame('FGTS_DIGITAL_PORTAL', $central->items()[0]['source']);
        $this->assertTrue($central->items()[0]['current_version']['has_document']);

        $second = $service->executeRun($service->createQueryRun($office, $client, $user));
        $this->assertSame(FgtsDigitalRunStatus::Succeeded, $second->status);
        $this->assertSame(1, TaxGuide::query()->withoutGlobalScopes()->count());
        $this->assertSame(1, TaxGuideVersion::query()->withoutGlobalScopes()->count());
    }

    public function test_pdf_is_delivered_by_existing_one_time_descriptor_and_emit_updates_mutation(): void
    {
        config()->set('fgts_digital.mutations_enabled', true);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        Sanctum::actingAs($admin);
        $service = app(FgtsDigitalPortalService::class);

        $query = $service->executeRun($service->createQueryRun($office, $client, $admin));
        $guideId = (int) $query->tax_guide_id;
        $token = $this->postJson('/api/v1/fiscal/guides/'.$guideId.'/download-token')
            ->assertOk()
            ->json('data.token');
        $download = $this->get('/api/v1/fiscal/guides/downloads/'.$token)->assertOk();
        $this->assertStringStartsWith('%PDF-', $download->streamedContent());

        $preview = $service->preview(
            $office,
            $client,
            $admin,
            FgtsDigitalGuideType::Monthly,
            ['competence_period_key' => '2026-07', 'amount_cents' => 184250],
        );
        $authorized = $service->authorizeEmission(
            $office,
            $preview['run'],
            $admin,
            (string) $preview['preview_token'],
            (string) $preview['run']->confirmation_phrase,
        );
        $emission = $service->executeRun($authorized['run']);

        $this->assertSame(FgtsDigitalRunStatus::Succeeded, $emission->status);
        $mutation = FiscalMutationOperation::query()->withoutGlobalScopes()->findOrFail($emission->fiscal_mutation_operation_id);
        $this->assertSame(FiscalMutationStatus::Confirmed, $mutation->status);
        $this->assertNotNull($mutation->evidence_ref);
    }

    public function test_ambiguous_portal_result_stops_as_reconciliation_required_without_retry(): void
    {
        config()->set('fgts_digital.mutations_enabled', true);
        $this->app->instance(FgtsDigitalPortalClient::class, new class implements FgtsDigitalPortalClient
        {
            public function execute(FgtsDigitalPortalRequest $request): FgtsDigitalPortalResult
            {
                if ($request->operation->value === 'PREVIEW') {
                    return new FgtsDigitalPortalResult('SUCCEEDED', 'PREVIEW_READY', 'Prévia pronta.', [
                        'preview' => ['selection_fingerprint' => str_repeat('a', 64)],
                    ]);
                }

                return new FgtsDigitalPortalResult(
                    'RECONCILIATION_REQUIRED',
                    'RECONCILIATION_REQUIRED',
                    'Clique final sem resposta conclusiva.',
                    [],
                );
            }
        });
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $admin = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        $service = app(FgtsDigitalPortalService::class);

        $preview = $service->preview(
            $office,
            $client,
            $admin,
            FgtsDigitalGuideType::Monthly,
            ['competence_period_key' => '2026-07', 'amount_cents' => 184250],
        );
        $authorized = $service->authorizeEmission(
            $office,
            $preview['run'],
            $admin,
            (string) $preview['preview_token'],
            (string) $preview['run']->confirmation_phrase,
        );
        $run = $service->executeRun($authorized['run']);

        $this->assertSame(FgtsDigitalRunStatus::ReconciliationRequired, $run->status);
        $this->assertTrue($run->status->isTerminal());
        $this->assertNull($run->fresh()->request_vault_object_id);
        $mutation = FiscalMutationOperation::query()->withoutGlobalScopes()->findOrFail($run->fiscal_mutation_operation_id);
        $this->assertSame(FiscalMutationStatus::UnknownResult, $mutation->status);
        $this->assertNull($mutation->evidence_ref);
    }
}
