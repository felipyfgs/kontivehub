<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CredentialStatus;
use App\Enums\OfficeRole;
use App\Jobs\Fiscal\SyncFgtsEsocialCompetenceJob;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\EsocialBxAccessLedger;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\User;
use App\Services\Esocial\EsocialBxAccessGuard;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        [, $client] = $this->tenant(OfficeRole::Admin);

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
        [$office, $client] = $this->tenant(OfficeRole::Admin);

        CarbonImmutable::setTestNow('2026-08-05 12:00:00-03:00');
        $this->postJson('/api/v1/fiscal/fgts/sync-now', $this->payload($client))
            ->assertStatus(423)
            ->assertJsonPath('code', 'ESOCIAL_BX_BLOCKED_WINDOW');
        $this->assertNoSideEffects();

        CarbonImmutable::setTestNow('2026-07-15 12:00:00-03:00');
        $guard = app(EsocialBxAccessGuard::class);
        for ($index = 0; $index < 10; $index++) {
            EsocialBxAccessLedger::query()->withoutGlobalScopes()->create([
                'office_id' => $office->id,
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
        [$office, $client] = $this->tenant(OfficeRole::Admin);
        $this->credential($office, $client);

        $this->postJson('/api/v1/fiscal/fgts/sync', [
            ...$this->payload($client),
            'create_run' => false,
            'dispatch_job' => true,
        ])->assertAccepted()
            ->assertJsonPath('data.queued', true)
            ->assertJsonPath('data.client_id', $client->id);

        Queue::assertPushed(
            SyncFgtsEsocialCompetenceJob::class,
            static fn ($job): bool => $job->officeId === $office->id && $job->clientId === $client->id,
        );
        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        $this->assertDatabaseCount('esocial_bx_access_ledgers', 0);

        Sanctum::actingAs(User::factory()->forOffice($office, OfficeRole::Viewer)->create());
        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($client))->assertForbidden();
    }

    public function test_foreign_client_and_establishment_are_not_disclosed(): void
    {
        [$office, $client] = $this->tenant(OfficeRole::Admin);
        $foreign = Client::factory()->forOffice(Office::factory()->create())->create();
        $this->credential($office, $client);

        $this->postJson('/api/v1/fiscal/fgts/sync', $this->payload($foreign))->assertNotFound();
        $this->postJson('/api/v1/fiscal/fgts/sync-now', [
            ...$this->payload($client),
            'establishment_id' => 999999,
        ])->assertNotFound();
        $this->assertNoSideEffects();
    }

    /** @return array{Office,Client} */
    private function tenant(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create(['root_cnpj' => '48123272']);
        Sanctum::actingAs(User::factory()->forOffice($office, $role)->create());

        return [$office, $client];
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

    private function credential(Office $office, Client $client): void
    {
        ClientCredential::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'status' => CredentialStatus::Active,
            'subject_name' => 'A1 metadata',
            'holder_cnpj' => '48123272000105',
            'fingerprint_sha256' => str_repeat('d', 64),
            'valid_from' => now()->subDay(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => 'NOT-MATERIALIZED-IN-PREFLIGHT',
            'activated_at' => now(),
        ]);
    }

    private function assertNoSideEffects(): void
    {
        $this->assertSame(0, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        $this->assertSame(0, EsocialBxAccessLedger::query()->withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }
}
