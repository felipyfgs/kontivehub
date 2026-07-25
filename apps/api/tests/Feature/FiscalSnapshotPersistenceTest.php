<?php

namespace Tests\Feature;

use App\DTO\Fiscal\FiscalPersistPayload;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalSnapshotPersistence;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FiscalSnapshotPersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_worker_versions_only_the_target_office_snapshot_without_current_office(): void
    {
        config(['fiscal_data_model.fail_closed_scopes' => true]);

        $targetOffice = Office::factory()->create();
        $targetClient = Client::factory()->forOffice($targetOffice)->create();
        $targetPreviousRun = $this->createRun($targetOffice, $targetClient, 'target-previous');
        $targetCurrentSnapshot = $this->createCurrentSnapshot($targetPreviousRun, 4);
        $targetRun = $this->createRun($targetOffice, $targetClient, 'target');

        $otherOffice = Office::factory()->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        $otherRun = $this->createRun($otherOffice, $otherClient, 'other');
        $otherCurrentSnapshot = $this->createCurrentSnapshot($otherRun, 7);

        app(CurrentOffice::class)->clear();

        self::assertNull(app(CurrentOffice::class)->id());
        self::assertNull(FiscalMonitoringRun::query()->find($targetRun->id));

        $persisted = app(FiscalSnapshotPersistence::class)->persist(new FiscalPersistPayload(
            run: $targetRun,
            result: FiscalRunResult::Failed,
            situation: FiscalSituation::Error,
            coverage: FiscalCoverage::Unknown,
            errorCode: 'WORKER_FAILURE',
            errorMessage: 'Falha controlada no worker.',
        ));

        self::assertSame($targetRun->id, $persisted['run']->id);
        self::assertSame(FiscalRunStatus::Failed, $persisted['run']->status);
        self::assertSame(FiscalRunResult::Failed, $persisted['run']->result);
        self::assertSame($targetOffice->id, $persisted['snapshot']?->office_id);
        self::assertSame($targetRun->id, $persisted['snapshot']?->run_id);
        self::assertSame(5, $persisted['snapshot']?->version);
        // Failed sem evidência não promove is_current — mantém relatório anterior.
        self::assertFalse((bool) $persisted['snapshot']?->is_current);

        $targetSnapshots = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->where('office_id', $targetOffice->id)
            ->where('client_id', $targetClient->id)
            ->where('system_code', $targetRun->system_code)
            ->where('service_code', $targetRun->service_code)
            ->whereNull('competence_id');

        self::assertSame(2, (clone $targetSnapshots)->count());
        self::assertSame(1, (clone $targetSnapshots)->where('is_current', true)->count());
        self::assertTrue((bool) FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->findOrFail($targetCurrentSnapshot->id)
            ->is_current);

        $untouchedRun = FiscalMonitoringRun::query()->withoutGlobalScopes()->findOrFail($otherRun->id);
        $untouchedSnapshot = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->findOrFail($otherCurrentSnapshot->id);

        self::assertSame(FiscalRunStatus::Running, $untouchedRun->status);
        self::assertNull($untouchedRun->result);
        self::assertSame(7, $untouchedSnapshot->version);
        self::assertTrue($untouchedSnapshot->is_current);
        self::assertSame($otherOffice->id, $untouchedSnapshot->office_id);
    }

    public function test_skip_reason_is_truncated_to_80_characters(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $run = $this->createRun($office, $client, 'skip-reason');

        $long = str_repeat('x', 120);
        $persisted = app(FiscalSnapshotPersistence::class)->persist(new FiscalPersistPayload(
            run: $run,
            result: FiscalRunResult::Blocked,
            situation: FiscalSituation::Blocked,
            coverage: FiscalCoverage::Unknown,
            skipReason: $long,
            errorCode: 'PROCURACAO_SYNC_FAILED',
            errorMessage: 'Falha ao sincronizar procurações: Elegibilidade Integra negada: AUTHORIZATION_MISSING',
        ));

        self::assertSame(80, mb_strlen((string) $persisted['run']->skip_reason));
        self::assertSame(mb_substr($long, 0, 80), $persisted['run']->skip_reason);
    }

    public function test_success_with_pdf_evidence_promotes_over_stale_error_without_evidence(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $failedRun = $this->createRun($office, $client, 'sitfis-failed');
        $failedRun->forceFill([
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'source_provenance' => 'SERPRO_REAL',
            'verification_state' => 'UNVERIFIED',
        ])->save();
        $staleError = FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $failedRun->office_id,
            'run_id' => $failedRun->id,
            'client_id' => $failedRun->client_id,
            'competence_id' => null,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Unknown,
            'version' => 1,
            'is_current' => true,
            'normalized' => [],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => 'SERPRO_REAL',
            'verification_state' => 'UNVERIFIED',
            'evidence_artifact_id' => null,
        ]);

        $okRun = $this->createRun($office, $client, 'sitfis-ok');
        $okRun->forceFill([
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'source_provenance' => 'SERPRO_REAL',
            'verification_state' => 'UNVERIFIED',
        ])->save();

        $persisted = app(FiscalSnapshotPersistence::class)->persist(new FiscalPersistPayload(
            run: $okRun->fresh(),
            result: FiscalRunResult::Success,
            situation: FiscalSituation::Attention,
            coverage: FiscalCoverage::Full,
            evidenceBytes: "%PDF-1.4\nfake-sitfis",
            evidenceContentType: 'application/pdf',
            evidenceSource: 'App\\Services\\Integra\\Sitfis\\SitfisSourceAdapter',
            sourceVersion: '2.0',
            normalized: [
                'layout_recognized' => true,
                'report_format' => 'pdf',
                'is_negative_certificate' => false,
            ],
            findings: [[
                'code' => 'SITFIS_PDF_UNSTRUCTURED',
                'severity' => 'MEDIUM',
                'title' => 'Relatório SITFIS em PDF — revise o artefato',
                'detail' => 'PDF oficial preservado.',
                'situation' => 'ATTENTION',
                'creates_pending' => false,
            ]],
        ));

        self::assertTrue((bool) $persisted['snapshot']?->is_current);
        self::assertNotNull($persisted['snapshot']?->evidence_artifact_id);
        self::assertFalse((bool) FiscalSnapshot::query()->withoutGlobalScopes()->findOrFail($staleError->id)->is_current);
    }

    private function createRun(Office $office, Client $client, string $suffix): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_MEI',
            'service_code' => 'DASN_SIMEI',
            'operation_code' => 'MONITORAR',
            'trigger' => FiscalTrigger::Scheduled,
            'idempotency_key' => "snapshot-persistence:{$suffix}",
            'status' => FiscalRunStatus::Running,
            'lease_owner' => "worker-{$suffix}",
            'locked_at' => now(),
        ]);
    }

    private function createCurrentSnapshot(FiscalMonitoringRun $run, int $version): FiscalSnapshot
    {
        return FiscalSnapshot::query()->withoutGlobalScopes()->create([
            'office_id' => $run->office_id,
            'run_id' => $run->id,
            'client_id' => $run->client_id,
            'competence_id' => $run->competence_id,
            'system_code' => $run->system_code,
            'service_code' => $run->service_code,
            'operation_code' => $run->operation_code,
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Unknown,
            'version' => $version,
            'is_current' => true,
            'normalized' => [],
            'observed_at' => now(),
            'created_at' => now(),
        ]);
    }
}
