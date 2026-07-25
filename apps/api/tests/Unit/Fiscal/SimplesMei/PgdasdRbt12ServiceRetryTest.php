<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\PgdasdOperationKind;
use App\Enums\PgdasdRbt12Status;
use App\Enums\TaxObligationApplicability;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Jobs\Fiscal\FetchPgdasdRbt12Job;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdOperation;
use App\Models\PgdasdRbt12Projection;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdMonitoringQueryService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdRbt12Service;
use App\Services\FiscalMonitoring\FiscalIdempotency;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PgdasdRbt12ServiceRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_reopens_recoverable_failed_reservation_and_dispatches_job(): void
    {
        Bus::fake([FetchPgdasdRbt12Job::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');
        $decl = $this->makeDeclaration($office, $client, $projection, '50029654202606001');
        $das = $this->makeDas($office, $client, $projection, '07202620140324992', '2026-07-07');

        $key = app(PgdasdRbt12Service::class)->sourceReferenceKey(
            (int) $office->id,
            (int) $client->id,
            '2026-06',
            (string) $das->das_number,
            $decl->declaration_number,
            $decl->transmitted_at?->toIso8601String(),
        );

        $failed = PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'source_reference_key' => $key,
            'source_das_number' => $das->das_number,
            'source_declaration_number' => $decl->declaration_number,
            'source_transmitted_at' => $decl->transmitted_at,
            'status' => PgdasdRbt12Status::Failed,
            'sanitized_error' => 'EXTRACT_QUERY_FAILED',
            'source_run_id' => $monitor->id,
            'extracted_at' => CarbonImmutable::parse('2026-07-20 17:36:35'),
            'attempted_at' => CarbonImmutable::parse('2026-07-20 17:36:12'),
            'metadata' => [
                'period_key' => '2026-06',
                'extract_run_id' => 138,
            ],
        ]);

        $reserved = app(PgdasdRbt12Service::class)->reserveFromOperations(
            $monitor,
            [$das, $decl],
            [$projection],
            '2026-06',
        );

        $this->assertCount(1, $reserved);
        $this->assertSame($failed->id, $reserved[0]->id);
        $this->assertSame(PgdasdRbt12Status::Pending, $reserved[0]->status);
        $this->assertNull($reserved[0]->sanitized_error);
        $this->assertNull($reserved[0]->attempted_at);
        $this->assertNull($reserved[0]->extracted_at);
        $this->assertArrayNotHasKey('extract_run_id', $reserved[0]->metadata ?? []);

        Bus::assertDispatched(FetchPgdasdRbt12Job::class, function (FetchPgdasdRbt12Job $job) use ($failed): bool {
            return $job->rbt12ProjectionId === (int) $failed->id;
        });
    }

    public function test_does_not_reopen_parsed_reservation(): void
    {
        Bus::fake([FetchPgdasdRbt12Job::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');
        $decl = $this->makeDeclaration($office, $client, $projection, '50029654202606001');
        $das = $this->makeDas($office, $client, $projection, '07202620140324992', '2026-07-07');

        $key = app(PgdasdRbt12Service::class)->sourceReferenceKey(
            (int) $office->id,
            (int) $client->id,
            '2026-06',
            (string) $das->das_number,
            $decl->declaration_number,
            $decl->transmitted_at?->toIso8601String(),
        );

        PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'source_reference_key' => $key,
            'source_das_number' => $das->das_number,
            'source_declaration_number' => $decl->declaration_number,
            'source_transmitted_at' => $decl->transmitted_at,
            'status' => PgdasdRbt12Status::Parsed,
            'total_cents' => 1_000_00,
            'source_run_id' => $monitor->id,
            'metadata' => ['period_key' => '2026-06'],
        ]);

        $reserved = app(PgdasdRbt12Service::class)->reserveFromOperations(
            $monitor,
            [$das, $decl],
            [$projection],
            '2026-06',
        );

        $this->assertSame([], $reserved);
        Bus::assertNotDispatched(FetchPgdasdRbt12Job::class);
        $this->assertSame(1, PgdasdRbt12Projection::query()->where('status', 'PARSED')->count());
    }

    public function test_fan_out_uses_only_latest_das_of_expected_period(): void
    {
        Bus::fake([FetchPgdasdRbt12Job::class]);

        [$office, $client, $expected, $monitor] = $this->seedExpectedPeriod('2026-06');
        $older = $this->makePgdasProjection($office, $client, '2026-05', 5);
        $this->makeDeclaration($office, $client, $expected, '50029654202606001');
        $this->makeDas($office, $client, $expected, '07202618865403722', '2026-07-01');
        $latest = $this->makeDas($office, $client, $expected, '07202620140324992', '2026-07-07');
        $this->makeDas($office, $client, $older, '07202617423286412', '2026-06-01');

        $reserved = app(PgdasdRbt12Service::class)->reserveFromOperations(
            $monitor,
            [],
            [$expected, $older],
            '2026-06',
        );

        $this->assertCount(1, $reserved);
        $this->assertSame('07202620140324992', $reserved[0]->source_das_number);
        $this->assertSame(1, PgdasdRbt12Projection::query()->count());
        Bus::assertDispatchedTimes(FetchPgdasdRbt12Job::class, 1);
        $this->assertSame($latest->das_number, $reserved[0]->source_das_number);
    }

    public function test_requeues_failed_correlated_extract_run(): void
    {
        Bus::fake([ExecuteFiscalMonitoringRunJob::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');
        $das = $this->makeDas($office, $client, $projection, '07202620140324992', '2026-07-07');
        $key = hash('sha256', 'retry-key');

        $failedRun = FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'CONSULTAR_EXTRATO',
            'operation_key' => 'pgdasd.consextrato',
            'trigger' => 'MANUAL',
            'idempotency_key' => 'manual:'.substr($key, 0, 40),
            'status' => FiscalRunStatus::Failed,
            'result' => FiscalRunResult::Failed,
            'error_code' => 'RATE_LIMIT_LOCAL',
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => 'pgdasd-rbt12-'.substr($key, 0, 50),
            'finished_at' => CarbonImmutable::now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        $rbt12 = PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'source_reference_key' => $key,
            'source_das_number' => $das->das_number,
            'status' => PgdasdRbt12Status::Pending,
            'source_run_id' => $monitor->id,
            'metadata' => [
                'period_key' => '2026-06',
                'extract_run_id' => $failedRun->id,
            ],
        ]);

        $correlationId = 'pgdasd-rbt12-'.substr($key, 0, 50);
        $slot = FiscalIdempotency::manualSlot($correlationId);
        $idempotencyKey = FiscalIdempotency::runKey(
            (int) $office->id,
            (int) $client->id,
            'INTEGRA_SN',
            'PGDASD',
            'CONSULTAR_EXTRATO',
            null,
            FiscalTrigger::Manual,
            $slot,
        );
        $failedRun->forceFill([
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
        ])->save();

        $run = app(PgdasdMonitoringQueryService::class)->enqueueAutomaticRbt12Extract($rbt12->fresh());

        $this->assertSame($failedRun->id, $run->id);
        $this->assertSame(FiscalRunStatus::Queued, $run->status);
        $this->assertNull($run->error_code);
        Bus::assertDispatched(ExecuteFiscalMonitoringRunJob::class, function (ExecuteFiscalMonitoringRunJob $job) use ($failedRun): bool {
            return (int) $job->fiscalMonitoringRunId === (int) $failedRun->id;
        });
    }

    public function test_without_das_reserves_from_declaration_and_dispatches_job(): void
    {
        Bus::fake([FetchPgdasdRbt12Job::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');
        $decl = $this->makeDeclaration($office, $client, $projection, '43996591202606001');

        $reserved = app(PgdasdRbt12Service::class)->reserveFromOperations(
            $monitor,
            [$decl],
            [$projection],
            '2026-06',
        );

        $this->assertCount(1, $reserved);
        $this->assertSame(PgdasdRbt12Status::Pending, $reserved[0]->status);
        $this->assertNull($reserved[0]->source_das_number);
        $this->assertSame('43996591202606001', $reserved[0]->source_declaration_number);
        $this->assertSame('declaration_recibo', $reserved[0]->metadata['source_kind'] ?? null);
        $this->assertSame(0, PgdasdRbt12Projection::query()->where('status', 'NO_DAS')->count());
        Bus::assertDispatchedTimes(FetchPgdasdRbt12Job::class, 1);
    }

    public function test_without_das_and_without_declaration_reserves_no_das(): void
    {
        Bus::fake([FetchPgdasdRbt12Job::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');

        $reserved = app(PgdasdRbt12Service::class)->reserveFromOperations(
            $monitor,
            [],
            [$projection],
            '2026-06',
        );

        $this->assertCount(1, $reserved);
        $this->assertSame(PgdasdRbt12Status::NoDas, $reserved[0]->status);
        Bus::assertNotDispatched(FetchPgdasdRbt12Job::class);
    }

    public function test_enqueue_rbt12_from_declaration_uses_consultar_recibo(): void
    {
        Bus::fake([ExecuteFiscalMonitoringRunJob::class]);

        [$office, $client, $projection, $monitor] = $this->seedExpectedPeriod('2026-06');
        $key = hash('sha256', 'decl-key');
        $rbt12 = PgdasdRbt12Projection::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'source_reference_key' => $key,
            'source_das_number' => null,
            'source_declaration_number' => '43996591202606001',
            'status' => PgdasdRbt12Status::Pending,
            'source_run_id' => $monitor->id,
            'metadata' => [
                'period_key' => '2026-06',
                'source_kind' => 'declaration_recibo',
            ],
        ]);

        $run = app(PgdasdMonitoringQueryService::class)->enqueueAutomaticRbt12Extract($rbt12->fresh());

        $this->assertSame('CONSULTAR_RECIBO', $run->operation_code);
        $this->assertSame('pgdasd.consdecrec', $run->operation_key);
        $this->assertSame('43996591202606001', $run->progress['numero_declaracao'] ?? null);
        $this->assertArrayNotHasKey('numero_das', $run->progress ?? []);
        Bus::assertDispatched(ExecuteFiscalMonitoringRunJob::class);
    }

    /**
     * @return array{0: Office, 1: Client, 2: TaxObligationProjection, 3: FiscalMonitoringRun}
     */
    private function seedExpectedPeriod(string $periodKey): array
    {
        $office = Office::factory()->create(['timezone' => 'America/Sao_Paulo']);
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
        ]);
        $month = (int) substr($periodKey, 5, 2);
        $projection = $this->makePgdasProjection($office, $client, $periodKey, $month);
        $monitor = FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
            'trigger' => 'MANUAL',
            'idempotency_key' => 'test-monitor-'.Str::uuid(),
            'status' => FiscalRunStatus::Completed,
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::UpToDate,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
            'progress' => ['expected_period_key' => $periodKey],
        ]);

        return [$office, $client, $projection, $monitor];
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

    private function makeDas(
        Office $office,
        Client $client,
        TaxObligationProjection $projection,
        string $dasNumber,
        string $issuedAt,
    ): PgdasdOperation {
        return PgdasdOperation::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'kind' => PgdasdOperationKind::Das,
            'period_key' => $projection->period_key,
            'logical_key' => 'das:'.$projection->period_key.':'.$dasNumber,
            'raw_operation_type' => 'DAS',
            'normalized_operation_type' => 'DAS',
            'das_number' => $dasNumber,
            'issued_at' => CarbonImmutable::parse($issuedAt),
            'first_seen_at' => CarbonImmutable::parse($issuedAt),
            'last_seen_at' => CarbonImmutable::parse($issuedAt),
        ]);
    }
}
