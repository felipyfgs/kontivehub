<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialStatus;
use App\Enums\TenantRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Jobs\Fiscal\SyncFgtsEsocialCompetenceJob;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialBxAccessLedger;
use App\Models\FiscalCategory;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Esocial\EsocialBxAccessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FgtsEsocialApiPreflightTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        config()->set('fgts_esocial.driver', 'official_bx');
        config()->set('fgts_esocial.environment', 'restricted');
        config()->set('fgts_esocial.production_egress_enabled', false);
        config()->set('fgts_esocial.kill_switch', false);
        config()->set('fgts_esocial.official_bx.daily_access_limit', 10);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_missing_credential_blocks_sync_before_run_job_or_ledger(): void
    {
        [, $client] = $this->tenant(TenantRole::TenantAdmin);

        $response = $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($client))
            ->assertStatus(422)
            ->assertJsonPath('code', 'ESOCIAL_BX_CREDENTIAL_MISSING')
            ->assertJsonPath('readiness.ready', false);

        $this->assertNoSideEffects();
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $client->root_cnpj, $encoded);
        $this->assertStringNotContainsString('vault_object_id', $encoded);
    }

    public function test_window_quota_and_production_gate_have_stable_http_statuses(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);

        CarbonImmutable::setTestNow('2026-08-05 12:00:00-03:00');
        $this->postJson('/api/v1/fiscal/fgts/sync-now', $this->payload($client))
            ->assertStatus(423)
            ->assertJsonPath('code', 'ESOCIAL_BX_BLOCKED_WINDOW');
        $this->assertNoSideEffects();

        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        $guard = app(EsocialBxAccessGuard::class);
        for ($index = 0; $index < 10; $index++) {
            EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'employer_hash' => $guard->employerHash($client),
                'environment' => 'restricted',
                'operation' => 'IDENTIFIERS_S-1299',
                'access_date' => '2026-07-15',
                'status' => 'FAILED',
            ]);
        }
        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($client))
            ->assertStatus(429)
            ->assertJsonPath('code', 'ESOCIAL_BX_QUOTA_EXHAUSTED');
        $this->assertDatabaseCount('esocial_bx_access_ledgers', 10);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        Queue::assertNothingPushed();

        EsocialBxAccessLedger::query()->withoutGlobalScopes()->delete();
        config()->set('fgts_esocial.environment', 'production');
        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($client))
            ->assertStatus(503)
            ->assertJsonPath('code', 'ESOCIAL_BX_PRODUCTION_EGRESS_DISABLED');
        $this->assertNoSideEffects();
    }

    public function test_ready_sync_queues_only_after_preflight_and_viewer_cannot_write(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);
        $this->credential($tenant, $client);

        $this->postJson('/api/v1/fiscal/fgts/sync', [
            ...$this->payload($client),
            'create_run' => false,
            'dispatch_job' => true,
        ])->assertAccepted()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.client_id', $client->id);

        Queue::assertPushed(
            SyncFgtsEsocialCompetenceJob::class,
            static fn ($job): bool => $job->tenantId === $tenant->id && $job->clientId === $client->id,
        );
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        $this->assertDatabaseCount('esocial_bx_access_ledgers', 0);

        Sanctum::actingAs(User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create());
        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($client))->assertForbidden();
    }

    public function test_default_sync_creates_one_run_and_dispatches_one_execution_path(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);
        $this->credential($tenant, $client);
        $this->fgtsCategory();

        $this->postJson('/api/v1/fiscal/fgts/sync', [
            ...$this->payload($client),
            'correlation_id' => 'fgts-esocial-single-path',
        ])->assertAccepted()
            ->assertJsonPath('data.run.correlation_id', 'fgts-esocial-single-path');

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->sole();
        $this->assertSame('2026-06', $run->progress['competence_period_key'] ?? null);
        Queue::assertPushed(
            SyncFgtsEsocialCompetenceJob::class,
            static fn (SyncFgtsEsocialCompetenceJob $job): bool => $job->runId === $run->id,
        );
        Queue::assertPushed(SyncFgtsEsocialCompetenceJob::class, 1);
        Queue::assertNotPushed(ExecuteFiscalMonitoringRunJob::class);
    }

    public function test_correlation_replay_reuses_run_without_duplicate_dispatch(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);
        $this->credential($tenant, $client);
        $this->fgtsCategory();
        $payload = [
            ...$this->payload($client),
            'correlation_id' => 'fgts-esocial-idempotent',
        ];

        $firstRun = $this->postJson('/api/v1/fiscal/fgts/sync', $payload)
            ->assertAccepted()
            ->json('data.run.id');
        $secondRun = $this->postJson('/api/v1/fiscal/fgts/sync', $payload)
            ->assertAccepted()
            ->json('data.run.id');

        $this->assertSame($firstRun, $secondRun);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 1);
        Queue::assertPushed(SyncFgtsEsocialCompetenceJob::class, 1);
        Queue::assertNotPushed(ExecuteFiscalMonitoringRunJob::class);
    }

    public function test_run_progress_failure_rolls_back_and_does_not_dispatch(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);
        $this->credential($tenant, $client);

        DB::unprepared(<<<'SQL'
            CREATE FUNCTION reject_fgts_esocial_progress() RETURNS trigger AS $$
            BEGIN
                IF NEW.progress IS NOT NULL THEN
                    RAISE EXCEPTION 'forced progress failure';
                END IF;
                RETURN NEW;
            END;
            $$ LANGUAGE plpgsql;

            CREATE TRIGGER reject_fgts_esocial_progress_update
            BEFORE UPDATE ON fiscal_monitoring_runs
            FOR EACH ROW EXECUTE FUNCTION reject_fgts_esocial_progress();
            SQL);

        $this->postJson('/api/v1/fiscal/fgts/sync', [
            ...$this->payload($client),
            'correlation_id' => 'fgts-esocial-rollback',
        ])->assertStatus(422)
            ->assertJsonPath('code', 'ESOCIAL_RUN_CREATION_FAILED');

        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_foreign_client_and_establishment_are_not_disclosed(): void
    {
        [$tenant, $client] = $this->tenant(TenantRole::TenantAdmin);
        $foreign = Client::factory()->forTenant(Tenant::factory()->create())->create();
        $this->credential($tenant, $client);

        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($foreign))->assertNotFound();
        $this->postJson('/api/v1/fiscal/fgts/sync-now', [
            ...$this->payload($client),
            'establishment_id' => 999999,
        ])->assertNotFound();
        $this->assertNoSideEffects();
    }

    /** @return array{Tenant,Client} */
    private function tenant(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create(['root_cnpj' => '48123272']);
        Sanctum::actingAs(User::factory()->forTenant($tenant, $role)->create());

        return [$tenant, $client];
    }

    /** @return array<string, mixed> */
    private function payload(Client $client): array
    {
        return [
            'client_id' => $client->id,
            'competence_period_key' => '2026-06',
            'create_run' => true,
            'dispatch_job' => true,
        ];
    }

    private function credential(Tenant $tenant, Client $client): void
    {
        ClientCredential::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'certificado metadata',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => str_repeat('d', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => '01J00000000000000000000000',
            'activated_at' => now(),
        ]);
    }

    private function assertNoSideEffects(): void
    {
        $this->assertSame(0, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    private function fgtsCategory(): FiscalCategory
    {
        return FiscalCategory::query()->create([
            'code' => 'FGTS',
            'name' => 'FGTS',
            'module_key' => 'fgts',
            'default_coverage' => 'PARTIAL',
            'default_mutability' => 'READ_ONLY',
            'system_code' => 'ESOCIAL',
            'service_code' => 'FGTS',
        ]);
    }
}
