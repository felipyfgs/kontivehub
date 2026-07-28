<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentTenant;
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
        [$tenant, $user, $client] = $this->seedActor();
        $run = $this->makeRun($tenant, $client);
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
            'tenant_id' => $tenant->id,
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
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/sitfis?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.evidence_artifact_id', $evidence->id)
            ->assertJsonPath('data.links.evidence_download', '/api/v1/fiscal/evidence/'.$evidence->id.'/download')
            ->assertJsonPath('data.is_negative_certificate', false);
    }

    public function test_show_without_artifact_has_null_evidence_link(): void
    {
        [$tenant, $user, $client] = $this->seedActor();
        $run = $this->makeRun($tenant, $client);

        FiscalSnapshot::query()->create([
            'tenant_id' => $tenant->id,
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
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/sitfis?client_id='.$client->id)
            ->assertOk()
            ->assertJsonPath('data.evidence_artifact_id', null)
            ->assertJsonPath('data.links.evidence_download', null);
    }

    public function test_show_validates_client_and_rejects_client_supplied_tenant_scope(): void
    {
        [$tenant, $user] = $this->seedActor();
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/sitfis?client_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
        $this->getJson('/api/v1/fiscal/sitfis?client_id=1&tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');
    }

    public function test_refresh_within_ttl_healthy_snapshot_does_not_enqueue(): void
    {
        [$tenant, $user, $client] = $this->seedActor();
        $run = $this->makeRun($tenant, $client);
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
            'tenant_id' => $tenant->id,
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
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/fiscal/sitfis/refresh', ['client_id' => $client->id])
            ->assertOk()
            ->assertJsonPath('data.enqueued', false)
            ->assertJsonPath('data.reason', 'WITHIN_TTL');
    }

    public function test_refresh_error_snapshot_enqueues_and_force_bypasses_ttl(): void
    {
        [$tenant, $user, $client] = $this->seedActor();
        $run = $this->makeRun($tenant, $client);

        FiscalSnapshot::query()->create([
            'tenant_id' => $tenant->id,
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
        app(CurrentTenant::class)->clear();

        $this->postJson('/api/v1/fiscal/sitfis/refresh', ['client_id' => $client->id])
            ->assertStatus(202)
            ->assertJsonPath('data.enqueued', true);

        // Segunda chamada com force em cima de snapshot saudável
        $run2 = $this->makeRun($tenant, $client);
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
            'tenant_id' => $tenant->id,
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
     * @return array{0: Tenant, 1: User, 2: Client}
     */
    private function seedActor(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $client = Client::factory()->for($tenant)->create(['is_active' => true]);

        return [$tenant, $user, $client];
    }

    private function makeRun(Tenant $tenant, Client $client): FiscalMonitoringRun
    {
        return FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
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
