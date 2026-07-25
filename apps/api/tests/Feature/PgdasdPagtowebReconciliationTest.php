<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalProfile;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\PgdasdOperationKind;
use App\Enums\SerproEnvironment;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PagtowebPaymentListItem;
use App\Models\PagtowebPaymentListObservation;
use App\Models\PgdasdOperation;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\TaxProxyPower;
use App\Services\Fiscal\Guides\PagtowebPaymentListAdapter;
use App\Services\Fiscal\Guides\PagtowebPaymentListCodec;
use App\Services\Fiscal\Guides\PagtowebPaymentListProjector;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebEvidenceReapplyService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebReconciliationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PgdasdPagtowebReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $originalConfig = [];

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-21 10:00:00');
        foreach ([
            'fiscal.profile',
            'serpro.default_environment',
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.enabled',
            'fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds',
        ] as $key) {
            $this->originalConfig[$key] = config($key);
        }
        config()->set('fiscal.profile', FiscalProfile::Dev->value);
        config()->set('serpro.default_environment', SerproEnvironment::Production->value);
        config()->set('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.enabled', true);
        config()->set('fiscal_monitoring.pgdasd_pagtoweb_reconciliation.negative_ttl_seconds', 86_400);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        foreach ($this->originalConfig as $key => $value) {
            config()->set($key, $value);
        }
        parent::tearDown();
    }

    public function test_codec_builds_numero_documento_lista_and_persists_only_digests(): void
    {
        $codec = app(PagtowebPaymentListCodec::class);
        $documents = ['07202619183811980', '07202619183811981'];

        $normalized = $codec->normalizeFilters([
            'numero_documento_lista' => $documents,
            'page' => 1,
            'per_page' => 100,
        ]);

        $this->assertSame($documents, $normalized['business_data']['numeroDocumentoLista']);
        $this->assertSame(100, $normalized['business_data']['tamanhoDaPagina']);
        $this->assertSame(
            array_map($codec->documentDigest(...), $documents),
            $normalized['filter_summary']['numero_documento_digests'],
        );
        $this->assertStringNotContainsString($documents[0], json_encode($normalized['filter_summary']));
        $this->assertSame(
            $documents,
            $codec->decryptDocumentNumbers($codec->encryptDocumentNumbers($documents)),
        );
    }

    public function test_projector_matches_digest_and_marks_only_consulted_documents(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);
        $projection = $this->projection($office, $client, '2026-06');
        $paidDocument = '07202619183811980';
        $missingDocument = '07202619183811981';
        $unconsultedDocument = '07202619183811982';
        $paid = $this->das($office, $client, $projection, $paidDocument);
        $missing = $this->das($office, $client, $projection, $missingDocument);
        $unconsulted = $this->das($office, $client, $projection, $unconsultedDocument);

        $otherOffice = Office::factory()->create();
        $otherClient = Client::factory()->for($otherOffice)->create(['is_active' => true]);
        $otherProjection = $this->projection($otherOffice, $otherClient, '2026-06');
        $crossTenant = $this->das($otherOffice, $otherClient, $otherProjection, $paidDocument);

        $run = $this->monitoringRun(
            $office,
            $client,
            PagtowebPaymentListAdapter::SERVICE,
            PagtowebPaymentListAdapter::OPERATION,
            FiscalRunResult::Success,
        );
        $codec = app(PagtowebPaymentListCodec::class);
        $normalized = $codec->normalizeFilters([
            'numero_documento_lista' => [$paidDocument, $missingDocument],
            'page' => 1,
            'per_page' => 100,
        ]);
        $items = $codec->decodePayments([
            'pagamentos' => [[
                'numeroDocumento' => $paidDocument,
                'dataArrecadacao' => '2026-07-20',
                'valorTotal' => '123.45',
            ]],
        ]);

        $projected = app(PagtowebPaymentListProjector::class)->project(
            $office,
            $client,
            $items,
            $normalized['filter_summary'],
            (int) $run->id,
            FiscalSourceProvenance::SerproReal->value,
            CarbonImmutable::now(),
        );

        $this->assertSame('PAID', $paid->refresh()->pagtoweb_payment_status);
        $this->assertSame(12345, $paid->pagtoweb_amount_cents);
        $this->assertSame('2026-07-20', $paid->pagtoweb_paid_at?->toDateString());
        $this->assertNotNull($paid->pagtoweb_source_item_id);
        $this->assertSame('NOT_FOUND', $missing->refresh()->pagtoweb_payment_status);
        $this->assertNotNull($missing->pagtoweb_verified_at);
        $this->assertNull($unconsulted->refresh()->pagtoweb_payment_status);
        $this->assertNull($crossTenant->refresh()->pagtoweb_payment_status);
        $this->assertStringNotContainsString(
            $paidDocument,
            json_encode($projected['observation']->filter_summary),
        );

        $unverified = $codec->normalizeFilters([
            'numero_documento_lista' => [$unconsultedDocument],
            'page' => 1,
            'per_page' => 100,
        ]);
        app(PagtowebPaymentListProjector::class)->project(
            $office,
            $client,
            [],
            $unverified['filter_summary'],
            (int) $run->id,
            FiscalSourceProvenance::Unverified->value,
            CarbonImmutable::now(),
        );
        $this->assertNull($unconsulted->refresh()->pagtoweb_payment_status);
    }

    public function test_leading_zero_pgdas_das_matches_pagtoweb_response_without_padding(): void
    {
        $codec = app(PagtowebPaymentListCodec::class);
        $localDas = '07202604328595614';
        $pagtowebDocument = '7202604328595614';

        $this->assertSame(
            $codec->canonicalizeDocumentNumber($localDas),
            $codec->canonicalizeDocumentNumber($pagtowebDocument),
        );
        $this->assertSame(
            $codec->documentDigest($localDas),
            $codec->documentDigest($pagtowebDocument),
        );

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);
        $projection = $this->projection($office, $client, '2026-01');
        $paid = $this->das($office, $client, $projection, $localDas);
        $run = $this->monitoringRun(
            $office,
            $client,
            PagtowebPaymentListAdapter::SERVICE,
            PagtowebPaymentListAdapter::OPERATION,
            FiscalRunResult::Success,
        );
        $normalized = $codec->normalizeFilters([
            'numero_documento_lista' => [$localDas],
            'page' => 1,
            'per_page' => 100,
        ]);
        $items = $codec->decodePayments([
            'pagamentos' => [[
                'numeroDocumento' => $pagtowebDocument,
                'dataArrecadacao' => '2026-02-18',
                'valorTotal' => '420.00',
            ]],
        ]);

        app(PagtowebPaymentListProjector::class)->project(
            $office,
            $client,
            $items,
            $normalized['filter_summary'],
            (int) $run->id,
            FiscalSourceProvenance::SerproReal->value,
            CarbonImmutable::now(),
        );

        $this->assertSame('PAID', $paid->refresh()->pagtoweb_payment_status);
        $this->assertSame(42000, $paid->pagtoweb_amount_cents);
        $this->assertSame('2026-02-18', $paid->pagtoweb_paid_at?->toDateString());
    }

    public function test_reapply_local_evidence_fixes_false_not_found_without_http(): void
    {
        Http::fake();
        $codec = app(PagtowebPaymentListCodec::class);
        $localDas = '07202604328595614';
        $pagtowebDocument = '7202604328595614';

        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);
        $projection = $this->projection($office, $client, '2026-01');
        $paid = $this->das($office, $client, $projection, $localDas);

        $run = $this->monitoringRun(
            $office,
            $client,
            PagtowebPaymentListAdapter::SERVICE,
            PagtowebPaymentListAdapter::OPERATION,
            FiscalRunResult::Success,
        );
        $run->forceFill([
            'progress' => [
                'pagtoweb_payment_list_documents_encrypted' => $codec->encryptDocumentNumbers([$localDas]),
            ],
        ])->save();

        // Simula evidência já persistida com item (digest canônico da resposta) e DAS ainda NOT_FOUND.
        $itemDigest = $codec->documentDigest($pagtowebDocument);
        $observation = PagtowebPaymentListObservation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'filter_summary' => [
                // digests legados (pré-canônico) — reapply deve recomputar via run criptografado
                'numero_documento_digests' => [hash_hmac('sha256', $localDas, (string) config('app.key'))],
                'page' => 1,
                'per_page' => 1,
            ],
            'returned_count' => 1,
            'digest' => hash('sha256', 'reapply-fixture'),
            'observed_at' => CarbonImmutable::now(),
            'source_run_id' => $run->id,
            'source_provenance' => FiscalSourceProvenance::SerproReal->value,
            'created_at' => CarbonImmutable::now(),
        ]);
        PagtowebPaymentListItem::query()->create([
            'observation_id' => $observation->id,
            'office_id' => $office->id,
            'client_id' => $client->id,
            'document_digest' => $itemDigest,
            'document_masked' => '••••5614',
            'paid_on' => '2026-02-18',
            'total_amount' => 420.0,
            'created_at' => CarbonImmutable::now(),
        ]);
        $paid->forceFill([
            'pagtoweb_payment_status' => 'NOT_FOUND',
            'pagtoweb_verified_at' => CarbonImmutable::now(),
        ])->save();

        $summary = app(PgdasdPagtowebEvidenceReapplyService::class)
            ->reapply((int) $office->id, (int) $client->id);

        $this->assertSame(1, $summary['observations']);
        $this->assertSame(1, $summary['paid']);
        $this->assertSame('PAID', $paid->refresh()->pagtoweb_payment_status);
        $this->assertSame(42000, $paid->pagtoweb_amount_cents);
        Http::assertNothingSent();
    }

    public function test_productive_monitor_enqueues_one_idempotent_batch_with_power_00004(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);
        $projection = $this->projection($office, $client, '2026-06');
        $document = '07202619183811980';
        $this->das($office, $client, $projection, $document);
        $this->power($office, $client);
        $sourceRun = $this->monitoringRun(
            $office,
            $client,
            'PGDASD',
            'MONITOR',
            FiscalRunResult::Success,
        );

        $service = app(PgdasdPagtowebReconciliationService::class);
        $first = $service->enqueueAfterProductiveMonitor($office, $client, $sourceRun);
        $second = $service->enqueueAfterProductiveMonitor($office, $client, $sourceRun);

        $this->assertSame(['queued' => 1, 'documents' => 1, 'reason' => 'QUEUED'], $first);
        $this->assertSame('ALREADY_QUEUED', $second['reason']);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('operation_key', PagtowebPaymentListAdapter::OPERATION_KEY)
            ->sole();
        $this->assertSame(FiscalTrigger::Reconciliation, $run->trigger);
        $this->assertStringNotContainsString($document, json_encode($run->progress));
        $this->assertSame(
            [$document],
            app(PagtowebPaymentListCodec::class)->decryptDocumentNumbers(
                $run->progress['pagtoweb_payment_list_documents_encrypted'],
            ),
        );
    }

    public function test_missing_power_does_not_dispatch_and_failed_source_does_not_write_negative_evidence(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);
        $projection = $this->projection($office, $client, '2026-06');
        $operation = $this->das($office, $client, $projection, '07202619183811980');
        $successfulSource = $this->monitoringRun(
            $office,
            $client,
            'PGDASD',
            'MONITOR',
            FiscalRunResult::Success,
        );

        $service = app(PgdasdPagtowebReconciliationService::class);
        $withoutPower = $service->enqueueAfterProductiveMonitor($office, $client, $successfulSource);
        $this->assertSame(0, $withoutPower['queued']);
        $this->assertStringContainsString('POWER', $withoutPower['reason']);

        $this->power($office, $client);
        $failedSource = $this->monitoringRun(
            $office,
            $client,
            'PGDASD',
            'MONITOR',
            FiscalRunResult::Failed,
        );
        $failed = $service->enqueueAfterProductiveMonitor($office, $client, $failedSource);

        $this->assertSame('SOURCE_NOT_PRODUCTIVE_PGDASD', $failed['reason']);
        $this->assertNull($operation->refresh()->pagtoweb_payment_status);
        Queue::assertNothingPushed();
    }

    private function projection(Office $office, Client $client, string $periodKey): TaxObligationProjection
    {
        $definition = TaxObligationDefinition::query()->firstOrCreate(
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
            'obligation_definition_id' => $definition->id,
            'period_key' => $periodKey,
            'period_year' => (int) substr($periodKey, 0, 4),
            'period_month' => (int) substr($periodKey, 5, 2),
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);
    }

    private function das(
        Office $office,
        Client $client,
        TaxObligationProjection $projection,
        string $document,
    ): PgdasdOperation {
        return PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => $projection->period_key,
            'logical_key' => 'das:'.$projection->period_key.':'.$document,
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => $document,
            'first_seen_at' => CarbonImmutable::now(),
            'last_seen_at' => CarbonImmutable::now(),
        ]);
    }

    private function monitoringRun(
        Office $office,
        Client $client,
        string $service,
        string $operation,
        FiscalRunResult $result,
    ): FiscalMonitoringRun {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => $service === 'PGDASD' ? 'INTEGRA_SN' : 'PAGTOWEB',
            'service_code' => $service,
            'operation_code' => $operation,
            'operation_key' => $service === 'PGDASD' ? 'pgdasd.consdeclaracao' : PagtowebPaymentListAdapter::OPERATION_KEY,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'trigger' => FiscalTrigger::Reconciliation,
            'idempotency_key' => fake()->uuid(),
            'status' => $result === FiscalRunResult::Success ? FiscalRunStatus::Completed : FiscalRunStatus::Failed,
            'result' => $result,
            'situation' => $result === FiscalRunResult::Success ? FiscalSituation::UpToDate : FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
    }

    private function power(Office $office, Client $client): void
    {
        TaxProxyPower::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Production->value,
            'author_identity' => '48123272000105',
            'contributor_cnpj' => '26461528000151',
            'system_code' => 'PAGTOWEB',
            'service_code' => 'PAGAMENTOS71',
            'power_code' => '00004',
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'status' => TaxProxyPowerStatus::Active,
            'verified_at' => CarbonImmutable::now(),
            'valid_to' => CarbonImmutable::now()->addYear(),
        ]);
    }
}
