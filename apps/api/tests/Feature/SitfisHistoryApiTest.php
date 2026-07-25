<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SitfisHistoryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_lists_local_consults_and_consolidates_reprocessed_snapshots(): void
    {
        [$office, $user, $client] = $this->seedActor();
        Establishment::factory()->forClient($client, '11365521000169')->create();

        $olderRun = $this->makeRun($office, $client);
        $olderEvidence = app(FiscalEvidenceStore::class)->store(
            run: $olderRun,
            bytes: '%PDF-1.4 older report',
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
        $original = $this->makeSnapshot($olderRun, [
            'version' => 1,
            'is_current' => false,
            'observed_at' => '2026-06-23 12:00:00+00',
            'evidence_artifact_id' => $olderEvidence->id,
            'situation' => FiscalSituation::Attention,
        ]);
        $reprocessed = $this->makeSnapshot($olderRun, [
            'version' => 2,
            'is_current' => false,
            'observed_at' => '2026-06-23 12:00:00+00',
            'evidence_artifact_id' => $olderEvidence->id,
            'situation' => FiscalSituation::Pending,
            'normalized' => ['reprocessed_from_snapshot_id' => $original->id],
        ]);

        $latestRun = $this->makeRun($office, $client);
        $latest = $this->makeSnapshot($latestRun, [
            'version' => 3,
            'is_current' => true,
            'observed_at' => '2026-07-15 12:00:00+00',
        ]);

        $unrelatedRun = $this->makeRun($office, $client);
        $this->makeSnapshot($unrelatedRun, [
            'system_code' => 'OTHER_SYSTEM',
            'service_code' => 'OTHER_SERVICE',
            'version' => 1,
            'is_current' => true,
            'observed_at' => '2026-07-20 12:00:00+00',
        ]);

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        Queue::fake([ExecuteFiscalMonitoringRunJob::class]);
        $runCount = FiscalMonitoringRun::query()->withoutGlobalScopes()->count();
        $snapshotCount = FiscalSnapshot::query()->withoutGlobalScopes()->count();

        $response = $this->getJson("/api/v1/fiscal/sitfis/clients/{$client->id}/history")
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonPath('data.client.legal_name', $client->legal_name)
            ->assertJsonPath('data.client.cnpj_masked', '11.***.***/****-69')
            ->assertJsonCount(2, 'data.searches')
            ->assertJsonPath('data.searches.0.id', $latest->id)
            ->assertJsonPath('data.searches.0.evidence_artifact_id', null)
            ->assertJsonPath('data.searches.0.links.evidence_download', null)
            ->assertJsonPath('data.searches.1.id', $reprocessed->id)
            ->assertJsonPath('data.searches.1.situation', FiscalSituation::Pending->value)
            ->assertJsonPath(
                'data.searches.1.links.evidence_download',
                "/api/v1/fiscal/evidence/{$olderEvidence->id}/download",
            );

        $this->assertSame('2026-07-15', substr((string) $response->json('data.searches.0.observed_at'), 0, 10));
        $this->assertSame('2026-06-23', substr((string) $response->json('data.searches.1.observed_at'), 0, 10));
        $this->assertSame($runCount, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        $this->assertSame($snapshotCount, FiscalSnapshot::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    public function test_history_is_fail_closed_for_another_office(): void
    {
        [$office, $user] = $this->seedActor();
        $foreignOffice = Office::factory()->create();
        $foreignClient = Client::factory()->for($foreignOffice)->create();
        $this->makeSnapshot($this->makeRun($foreignOffice, $foreignClient));

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->getJson("/api/v1/fiscal/sitfis/clients/{$foreignClient->id}/history")
            ->assertNotFound()
            ->assertJsonPath('code', 'CLIENT_NOT_FOUND');

        $this->getJson("/api/v1/fiscal/sitfis/clients/{$foreignClient->id}/history?office_id={$office->id}")
            ->assertStatus(422)
            ->assertJsonPath('code', 'CLIENT_OFFICE_ID_REJECTED');
    }

    /** @return array{0: Office, 1: User, 2: Client} */
    private function seedActor(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->for($office)->create([
            'legal_name' => 'COM CONSTRUCOES E EMPREENDIMENTOS LTDA',
            'is_active' => true,
        ]);

        return [$office, $user, $client];
    }

    private function makeRun(
        Office $office,
        Client $client,
        FiscalRunResult $result = FiscalRunResult::Success,
    ): FiscalMonitoringRun {
        return FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'sitfis-history-'.Str::uuid(),
            'status' => $result === FiscalRunResult::Failed ? 'FAILED' : 'COMPLETED',
            'result' => $result,
            'situation' => $result === FiscalRunResult::Failed
                ? FiscalSituation::Error
                : FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => $result === FiscalRunResult::Failed
                ? FiscalVerificationState::Unverified
                : FiscalVerificationState::Verified,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeSnapshot(FiscalMonitoringRun $run, array $overrides = []): FiscalSnapshot
    {
        return FiscalSnapshot::query()->create(array_merge([
            'office_id' => $run->office_id,
            'run_id' => $run->id,
            'client_id' => $run->client_id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => false,
            'normalized' => [],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ], $overrides));
    }
}
