<?php

namespace Tests\Unit\Integra\Sitfis;

use App\Contracts\EnsuresClientProcuracaoForConsult;
use App\Contracts\IntegraEligibilityEvaluating;
use App\Contracts\ResolvesSerproCapabilityDriver;
use App\Contracts\SerproOperationExecutor;
use App\Contracts\SitfisIdentityResolving;
use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Serpro\EligibilityResult;
use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\SerproCapabilityDriver;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\SerproContract;
use App\Services\Integra\Sitfis\SitfisFlowService;
use App\Services\Integra\Sitfis\SitfisProtocolState;
use App\Services\Integra\Sitfis\SitfisReportParser;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class SitfisFlowServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_solicit_persists_protocol_and_requeues_with_wait(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $protocol = str_repeat('P', 140);

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 200,
                body: ['dados' => ['protocoloRelatorio' => $protocol, 'tempoEspera' => 30]],
                dados: ['protocoloRelatorio' => $protocol, 'tempoEspera' => 30],
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service = $this->makeService($operations);
        $result = $service->execute($this->adapterRequest($office, $client, $run));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(FiscalSituation::Processing, $result->situation);
        $this->assertTrue($result->shouldRequeue);
        $this->assertSame($protocol, $result->progress['protocol'] ?? null);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_MIN_PERIOD, $result->progress['phase'] ?? null);
        $this->assertStringStartsWith('protocol:', (string) $result->progressCursor);
        $this->assertLessThanOrEqual(64, strlen((string) $result->progressCursor));
    }

    public function test_solicit_304_uses_protocol_from_etag_without_force_retry(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $protocol = str_repeat('E', 160);

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 304,
                body: [],
                errorCode: 'NOT_MODIFIED',
                errorMessage: 'Conteúdo não modificado (cache/ETag).',
                etag: '"protocoloRelatorio:'.$protocol.'"',
                businessStatus: 'NOT_MODIFIED',
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service = $this->makeService($operations);
        $result = $service->execute($this->adapterRequest($office, $client, $run));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(FiscalSituation::Processing, $result->situation);
        $this->assertTrue($result->shouldRequeue);
        $this->assertSame($protocol, $result->progress['protocol'] ?? null);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_MIN_PERIOD, $result->progress['phase'] ?? null);
    }

    public function test_solicit_304_without_etag_waits_until_expires(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $expires = CarbonImmutable::now()->addMinutes(45)->toRfc7231String();

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 304,
                body: [],
                headers: ['expires' => $expires],
                errorCode: 'NOT_MODIFIED',
                errorMessage: 'Conteúdo não modificado (cache/ETag).',
                expiresHeader: $expires,
                businessStatus: 'NOT_MODIFIED',
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service = $this->makeService($operations);
        $result = $service->execute($this->adapterRequest($office, $client, $run));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(FiscalSituation::Processing, $result->situation);
        $this->assertTrue($result->shouldRequeue);
        $this->assertNull($result->progress['protocol'] ?? null);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY, $result->progress['phase'] ?? null);
        $this->assertGreaterThanOrEqual(60, (int) ($result->requeueAfterSeconds ?? 0));
        $this->assertNull($result->errorCode);
    }

    public function test_solicit_304_without_etag_or_expires_uses_fallback_wait(): void
    {
        [$office, $client, $run] = $this->seedRun();

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 304,
                body: [],
                errorCode: 'NOT_MODIFIED',
                errorMessage: 'Conteúdo não modificado (cache/ETag).',
                businessStatus: 'NOT_MODIFIED',
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service = $this->makeService($operations);
        $result = $service->execute($this->adapterRequest($office, $client, $run));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY, $result->progress['phase'] ?? null);
        $this->assertSame(900, (int) ($result->requeueAfterSeconds ?? 0));
        $this->assertNull($result->errorCode);
    }

    public function test_waiting_cache_expiry_requeues_without_solicit_call(): void
    {
        [$office, $client, $run] = $this->seedRun();

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')->never();

        $ensure = Mockery::mock(EnsuresClientProcuracaoForConsult::class);
        $ensure->shouldReceive('ensure')->never();

        $service = $this->makeService($operations, $ensure);
        $result = $service->execute($this->adapterRequest($office, $client, $run, [
            'phase' => SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY,
            'requested_at' => CarbonImmutable::now()->toIso8601String(),
            'not_before' => CarbonImmutable::now()->addMinutes(20)->toIso8601String(),
            'poll_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'requeue_after_seconds' => 1200,
        ]));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY, $result->progress['phase'] ?? null);
        $this->assertTrue($result->shouldRequeue);
    }

    public function test_after_cache_expiry_resumes_solicit(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $protocol = str_repeat('R', 120);

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 200,
                body: ['dados' => ['protocoloRelatorio' => $protocol, 'tempoEspera' => 30]],
                dados: ['protocoloRelatorio' => $protocol, 'tempoEspera' => 30],
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service = $this->makeService($operations);
        $result = $service->execute($this->adapterRequest($office, $client, $run, [
            'phase' => SitfisProtocolState::PHASE_WAITING_CACHE_EXPIRY,
            'requested_at' => CarbonImmutable::now()->subHours(2)->toIso8601String(),
            'not_before' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            'poll_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'requeue_after_seconds' => 60,
        ]));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame($protocol, $result->progress['protocol'] ?? null);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_MIN_PERIOD, $result->progress['phase'] ?? null);
    }

    public function test_ensure_proxy_power_blocks_before_serpro_call(): void
    {
        [$office, $client, $run] = $this->seedRun();

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')->never();

        $ensure = Mockery::mock(EnsuresClientProcuracaoForConsult::class);
        $ensure->shouldReceive('ensure')
            ->once()
            ->andReturn([
                'ok' => false,
                'synced' => true,
                'code' => 'PROXY_POWER_MISSING',
                'message' => 'Poder 00002 ausente.',
            ]);

        $service = $this->makeService($operations, $ensure);
        $result = $service->execute($this->adapterRequest($office, $client, $run));

        $this->assertSame(FiscalRunResult::Blocked, $result->result);
        $this->assertSame(FiscalSituation::Blocked, $result->situation);
        $this->assertSame('PROXY_POWER_MISSING', $result->errorCode);
    }

    public function test_waiting_min_period_requeues_without_emit_call(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $protocol = 'PROTOCOLO-TESTE-SITFIS';

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')->never();

        $ensure = Mockery::mock(EnsuresClientProcuracaoForConsult::class);
        $ensure->shouldReceive('ensure')->never();

        $service = $this->makeService($operations, $ensure);
        $result = $service->execute($this->adapterRequest($office, $client, $run, [
            'phase' => SitfisProtocolState::PHASE_WAITING_MIN_PERIOD,
            'protocol' => $protocol,
            'requested_at' => CarbonImmutable::now()->toIso8601String(),
            'not_before' => CarbonImmutable::now()->addMinutes(5)->toIso8601String(),
            'poll_count' => 0,
            'correlation_id' => (string) Str::uuid(),
            'requeue_after_seconds' => 300,
        ]));

        $this->assertSame(FiscalRunResult::Partial, $result->result);
        $this->assertSame(SitfisProtocolState::PHASE_WAITING_MIN_PERIOD, $result->progress['phase'] ?? null);
        $this->assertSame($protocol, $result->progress['protocol'] ?? null);
    }

    public function test_emit_still_processing_requeues_then_success_parses_report(): void
    {
        [$office, $client, $run] = $this->seedRun();
        $protocol = 'PROTOCOLO-EMIT-OK';

        $operations = Mockery::mock(SerproOperationExecutor::class);
        $operations->shouldReceive('executeRequest')
            ->once()
            ->withArgs(function (IntegraRequest $request) use ($protocol): bool {
                return $request->operationKey === 'sitfis.emitir_relatorio'
                    && ($request->businessData['protocoloRelatorio'] ?? null) === $protocol;
            })
            ->andReturn(new IntegraResponse(
                success: false,
                httpStatus: 202,
                body: [],
                errorCode: 'STILL_PROCESSING',
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
                retryAfterSeconds: 60,
            ));

        $service = $this->makeService($operations);
        $processing = $service->execute($this->adapterRequest($office, $client, $run, [
            'phase' => SitfisProtocolState::PHASE_WAITING_MIN_PERIOD,
            'protocol' => $protocol,
            'requested_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String(),
            'not_before' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            'poll_count' => 0,
            'correlation_id' => (string) Str::uuid(),
        ]));

        $this->assertSame(FiscalRunResult::Partial, $processing->result);
        $this->assertSame(SitfisProtocolState::PHASE_POLLING_EMIT, $processing->progress['phase'] ?? null);
        $this->assertSame(1, $processing->progress['poll_count'] ?? null);

        $operations2 = Mockery::mock(SerproOperationExecutor::class);
        $operations2->shouldReceive('executeRequest')
            ->once()
            ->andReturn(new IntegraResponse(
                success: true,
                httpStatus: 200,
                body: ['dados' => [
                    'pendencias' => [
                        ['codigo' => 'DEB001', 'descricao' => 'Débito de teste'],
                    ],
                    'dataConsulta' => '2026-07-01',
                ]],
                dados: [
                    'pendencias' => [
                        ['codigo' => 'DEB001', 'descricao' => 'Débito de teste'],
                    ],
                    'dataConsulta' => '2026-07-01',
                ],
                sourceProvenance: FiscalSourceProvenance::SerproReal->value,
            ));

        $service2 = $this->makeService($operations2);
        $done = $service2->execute($this->adapterRequest($office, $client, $run, [
            'phase' => SitfisProtocolState::PHASE_POLLING_EMIT,
            'protocol' => $protocol,
            'requested_at' => CarbonImmutable::now()->subMinutes(2)->toIso8601String(),
            'not_before' => CarbonImmutable::now()->subMinute()->toIso8601String(),
            'poll_count' => 1,
            'correlation_id' => (string) Str::uuid(),
        ]));

        $this->assertSame(FiscalRunResult::Success, $done->result);
        $this->assertSame(SitfisProtocolState::PHASE_DONE, $done->progress['phase'] ?? null);
        $this->assertSame($protocol, $done->progress['protocol'] ?? null);
        $this->assertFalse((bool) ($done->normalized['is_negative_certificate'] ?? true));
        $this->assertNotNull($done->evidenceBytes);
    }

    /**
     * @return array{0: Office, 1: Client, 2: FiscalMonitoringRun}
     */
    private function seedRun(): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->create(['office_id' => $office->id]);
        $run = FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual->value,
            'idempotency_key' => 'sitfis-flow-'.uniqid(),
            'status' => 'RUNNING',
            'situation' => FiscalSituation::Processing,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        return [$office, $client, $run];
    }

    /**
     * @param  array<string, mixed>  $progress
     */
    private function adapterRequest(
        Office $office,
        Client $client,
        FiscalMonitoringRun $run,
        array $progress = [],
    ): FiscalAdapterRequest {
        return new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_SITFIS',
            serviceCode: 'SITFIS',
            operationCode: 'MONITOR',
            trigger: FiscalTrigger::Manual,
            progressCursor: $progress === [] ? 'solicit' : SitfisFlowService::cursorForProtocol((string) ($progress['protocol'] ?? '')),
            progress: $progress,
        );
    }

    private function makeService(
        SerproOperationExecutor $operations,
        ?EnsuresClientProcuracaoForConsult $procuracaoEnsure = null,
    ): SitfisFlowService {
        $contract = new SerproContract;
        $contract->forceFill([
            'id' => 1,
            'environment' => SerproEnvironment::Trial->value,
            'contractor_cnpj' => '11222333000181',
            'status' => 'ACTIVE',
        ]);

        $identities = Mockery::mock(SitfisIdentityResolving::class);
        $identities->shouldReceive('resolve')->andReturn([
            'environment' => SerproEnvironment::Trial,
            'contract' => $contract,
            'contractor_cnpj' => '11222333000181',
            'author_identity' => '11222333000181',
            'contributor_cnpj' => '11222333000181',
        ]);

        $eligibility = Mockery::mock(IntegraEligibilityEvaluating::class);
        $eligibility->shouldReceive('evaluate')->andReturn(EligibilityResult::ok());
        $eligibility->shouldReceive('touchRateLimit')->andReturnNull();

        $drivers = Mockery::mock(ResolvesSerproCapabilityDriver::class);
        $drivers->shouldReceive('forCapability')->with('sitfis')->andReturn(SerproCapabilityDriver::Fixture);

        if ($procuracaoEnsure === null) {
            $procuracaoEnsure = Mockery::mock(EnsuresClientProcuracaoForConsult::class);
            $procuracaoEnsure->shouldReceive('ensure')->andReturn([
                'ok' => true,
                'synced' => false,
                'code' => null,
                'message' => null,
            ]);
        }

        return new SitfisFlowService(
            operations: $operations,
            identities: $identities,
            parser: new SitfisReportParser,
            eligibility: $eligibility,
            drivers: $drivers,
            procuracaoEnsure: $procuracaoEnsure,
        );
    }
}
