<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\EsocialEventClient;
use App\Contracts\SecureObjectStore;
use App\DTO\Esocial\EsocialEventDto;
use App\DTO\Esocial\EsocialFetchRequest;
use App\DTO\Esocial\EsocialFetchResult;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\CredentialStatus;
use App\Enums\EsocialEventCode;
use App\Enums\FgtsIndependentState;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\SyncFgtsEsocialCompetenceJob;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialEventEvidence;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
use App\Services\Esocial\EsocialBxReadinessService;
use App\Services\Esocial\EsocialEvidencePersistence;
use App\Services\Esocial\FgtsEsocialMonitoringService;
use App\Services\Esocial\FgtsEsocialSourceAdapter;
use App\Services\Esocial\FgtsIndependentStateProjector;
use App\Services\FiscalMonitoring\FiscalMonitoringScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FgtsEsocialRuntimeIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        config()->set('fgts_esocial.kill_switch', false);
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_official_xml_is_idempotent_and_projects_independent_states(): void
    {
        $store = new CountingSecureObjectStore;
        $this->app->instance(SecureObjectStore::class, $store);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $observedAt = CarbonImmutable::parse('2026-07-10 15:00:00-03:00');
        $events = [
            new EsocialEventDto(
                eventCode: EsocialEventCode::S1299,
                competencePeriodKey: '2026-06',
                payloadBytes: '<eSocial><evtFechaEvPer Id="ID-CLOSURE"/></eSocial>',
                eventVersion: 'S-1.3',
                receiptNumber: '1.2.000000000000001',
                observedAt: $observedAt,
                metadata: ['source' => 'ESOCIAL_BX_OFFICIAL'],
            ),
            new EsocialEventDto(
                eventCode: EsocialEventCode::S5013,
                competencePeriodKey: '2026-06',
                payloadBytes: '<eSocial><evtFGTS Id="ID-TOTALIZER"/></eSocial>',
                eventVersion: 'S-1.3',
                observedAt: $observedAt->addMinute(),
                metadata: ['source' => 'ESOCIAL_BX_OFFICIAL'],
            ),
        ];
        $persistence = app(EsocialEvidencePersistence::class);

        $first = $persistence->persistMany($office, $client, $events);
        $repeated = $persistence->persistMany($office, $client, $events);

        $this->assertSame(array_column($first, 'id'), array_column($repeated, 'id'));
        $this->assertSame(2, EsocialEventEvidence::query()->withoutGlobalScopes()->count());
        $this->assertSame(2, $store->puts);
        $this->assertSame('application/xml', $first[0]->content_type);
        $this->assertFalse((bool) $first[0]->is_quarantined);

        $projection = app(FgtsIndependentStateProjector::class)->project(
            '2026-06',
            $persistence->listForCompetence($office, $client, '2026-06'),
            CarbonImmutable::now(),
        );

        $this->assertSame(FgtsIndependentState::Confirmed, $projection->closureStatus);
        $this->assertSame(FgtsIndependentState::Present, $projection->totalizationStatus);
        $this->assertSame(FgtsIndependentState::Unsupported, $projection->guideStatus);
        $this->assertSame(FgtsIndependentState::Unsupported, $projection->paymentStatus);
        $this->assertSame(FiscalCoverage::Partial, $projection->coverage);
        $this->assertSame(FiscalSituation::Attention, $projection->situation);
        $this->assertFalse($projection->normalized['guide_consulted']);
        $this->assertFalse($projection->normalized['payment_consulted']);
        $this->assertFalse($projection->normalized['declares_fgts_digital_debt']);
    }

    public function test_source_adapter_preserves_blocked_and_retryable_official_codes(): void
    {
        [$office, $client] = $this->readyTenant();
        $run = $this->createMonitoringRun($office, $client);
        $clientDouble = new MutableEsocialEventClient(new EsocialFetchResult(
            success: false,
            errorCode: 'ESOCIAL_BX_QUOTA_EXHAUSTED',
            errorMessage: 'SEGREDO REMOTO NÃO PODE VAZAR',
            diagnostics: ['blocked' => true, 'official_code' => '405'],
        ));
        $this->app->instance(EsocialEventClient::class, $clientDouble);
        $adapter = app(FgtsEsocialSourceAdapter::class);
        $request = new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'ESOCIAL',
            serviceCode: 'FGTS',
            operationCode: 'MONITOR',
            trigger: FiscalTrigger::Manual,
            context: ['competence_period_key' => '2026-06'],
        );

        $blocked = $adapter->execute($request);
        $this->assertSame(FiscalRunResult::Blocked, $blocked->result);
        $this->assertSame('ESOCIAL_BX_QUOTA_EXHAUSTED', $blocked->errorCode);
        $this->assertStringNotContainsString('SEGREDO REMOTO', (string) $blocked->errorMessage);

        $clientDouble->result = new EsocialFetchResult(
            success: false,
            errorCode: 'ESOCIAL_BX_HTTP_ERROR',
            errorMessage: 'OUTRO SEGREDO REMOTO',
            diagnostics: ['retryable' => true],
        );
        $retryable = $adapter->execute($request);
        $this->assertSame(FiscalRunResult::Requeued, $retryable->result);
        $this->assertSame(FiscalSituation::Unknown, $retryable->situation);
        $this->assertSame(FiscalCoverage::Partial, $retryable->coverage);
        $this->assertTrue($retryable->shouldRequeue);
        $this->assertSame(120, $retryable->requeueAfterSeconds);
        $this->assertSame('ESOCIAL_BX_HTTP_ERROR', $retryable->errorCode);
        $this->assertStringNotContainsString('OUTRO SEGREDO REMOTO', (string) $retryable->errorMessage);
    }

    public function test_scheduler_and_horizon_job_stop_before_client_egress_when_readiness_is_blocked(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        $schedule = FiscalMonitoringSchedule::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'ESOCIAL',
            'service_code' => 'FGTS',
            'operation_code' => 'MONITOR',
            'is_enabled' => true,
            'interval_minutes' => 60,
            'preferred_minute' => 0,
            'next_run_at' => CarbonImmutable::now()->subMinute(),
        ]);
        $clientDouble = new MutableEsocialEventClient(EsocialFetchResult::emptySuccess());
        $this->app->instance(EsocialEventClient::class, $clientDouble);

        $outcome = app(FiscalMonitoringScheduler::class)->claimAndEnqueue(
            $schedule,
            CarbonImmutable::now(),
        );

        $this->assertSame('blocked', $outcome);
        $this->assertSame('ESOCIAL_BX_CREDENTIAL_MISSING', $schedule->fresh()->last_skip_reason);
        $this->assertSame(0, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();

        $job = new SyncFgtsEsocialCompetenceJob(
            $office->id,
            $client->id,
            '2026-06',
        );
        $job->handle(
            app(FgtsEsocialMonitoringService::class),
            app(EsocialBxReadinessService::class),
        );

        $this->assertSame(0, $clientDouble->calls);
        $this->assertSame(0, EsocialEventEvidence::query()->withoutGlobalScopes()->count());
    }

    /** @return array{Office,Client} */
    private function readyTenant(): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        ClientCredential::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'Metadata A1 de teste',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => str_repeat('e', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => 'NOT-MATERIALIZED-BY-DOUBLE',
            'activated_at' => now(),
        ]);

        return [$office, $client];
    }

    private function createMonitoringRun(Office $office, Client $client): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'ESOCIAL',
            'service_code' => 'FGTS',
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'fgts-esocial-runtime-test',
            'status' => FiscalRunStatus::Running,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
        ]);
    }
}

final class MutableEsocialEventClient implements EsocialEventClient
{
    public int $calls = 0;

    public function __construct(public EsocialFetchResult $result) {}

    public function fetchEvents(EsocialFetchRequest $request): EsocialFetchResult
    {
        $this->calls++;

        return $this->result;
    }
}

final class CountingSecureObjectStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    public int $puts = 0;

    public function put(string $plaintext, array $metadata = []): string
    {
        $this->puts++;
        $id = str_pad('ESOCIAL'.(string) $this->puts, 26, '0');
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        return $this->objects[$objectId];
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return array_key_exists($objectId, $this->objects);
    }
}
