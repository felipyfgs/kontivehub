<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SitfisSituationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['fiscal_monitoring.enabled' => true]);
    }

    public function test_show_includes_evidence_download_link_when_artifact_exists(): void
    {
        [$office, $user, $client] = $this->seedActor();
        $run = $this->makeRun($office, $client);
        $evidence = app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: '%PDF-1.4 test',
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
        $evidence->forceFill([
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ])->save();

        FiscalSnapshot::query()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['protocol' => 'ABC', 'situation' => 'PENDING'],
            'observed_at' => now(),
            'created_at' => now(),
            'evidence_artifact_id' => $evidence->id,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->getJson('/api/v1/fiscal/sitfis?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.evidence_artifact_id', $evidence->id)
            ->assertJsonPath('data.links.evidence_download', '/api/v1/fiscal/evidence/'.$evidence->id.'/download')
            ->assertJsonPath('data.is_negative_certificate', false);
    }

    public function test_show_without_artifact_has_null_evidence_link(): void
    {
        [$office, $user, $client] = $this->seedActor();
        $run = $this->makeRun($office, $client);

        FiscalSnapshot::query()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['situation' => 'ERROR'],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->getJson('/api/v1/fiscal/sitfis?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.evidence_artifact_id', null)
            ->assertJsonPath('data.links.evidence_download', null);
    }

    public function test_refresh_within_ttl_healthy_snapshot_does_not_enqueue(): void
    {
        [$office, $user, $client] = $this->seedActor();
        $run = $this->makeRun($office, $client);
        $evidence = app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: '%PDF-1.4 ok',
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
        $evidence->forceFill([
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ])->save();

        FiscalSnapshot::query()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::UpToDate,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['situation' => 'UP_TO_DATE'],
            'observed_at' => now(),
            'created_at' => now(),
            'evidence_artifact_id' => $evidence->id,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->postJson('/api/v1/fiscal/sitfis/refresh', ['client_id' => $client->id])
            ->assertOk()
            ->assertJsonPath('data.enqueued', false)
            ->assertJsonPath('data.reason', 'WITHIN_TTL');
    }

    public function test_refresh_error_snapshot_enqueues_and_force_bypasses_ttl(): void
    {
        [$office, $user, $client] = $this->seedActor();
        $run = $this->makeRun($office, $client);

        FiscalSnapshot::query()->create([
            'office_id' => $office->id,
            'run_id' => $run->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Error,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => true,
            'normalized' => ['situation' => 'ERROR'],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Unverified,
        ]);

        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->postJson('/api/v1/fiscal/sitfis/refresh', ['client_id' => $client->id])
            ->assertStatus(202)
            ->assertJsonPath('data.enqueued', true);

        // Segunda chamada com force em cima de snapshot saudável
        $run2 = $this->makeRun($office, $client);
        $evidence = app(FiscalEvidenceStore::class)->store(
            run: $run2,
            bytes: '%PDF-1.4 two',
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
        $evidence->forceFill([
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ])->save();

        FiscalSnapshot::query()->withoutGlobalScopes()
            ->where('client_id', $client->id)
            ->where('is_current', true)
            ->update(['is_current' => false]);

        FiscalSnapshot::query()->create([
            'office_id' => $office->id,
            'run_id' => $run2->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'version' => 2,
            'is_current' => true,
            'normalized' => ['situation' => 'PENDING'],
            'observed_at' => now(),
            'created_at' => now(),
            'evidence_artifact_id' => $evidence->id,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);

        $this->postJson('/api/v1/fiscal/sitfis/refresh', [
            'client_id' => $client->id,
            'force' => true,
        ])
            ->assertStatus(202)
            ->assertJsonPath('data.enqueued', true);
    }

    /**
     * @return array{0: Office, 1: User, 2: Client}
     */
    private function seedActor(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->for($office)->create(['is_active' => true]);

        return [$office, $user, $client];
    }

    private function makeRun(Office $office, Client $client): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SITFIS',
            'service_code' => 'SITFIS',
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual->value,
            'idempotency_key' => 'sitfis-api-'.uniqid(),
            'status' => 'COMPLETED',
            'result' => FiscalRunResult::Success->value,
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);
    }
}
