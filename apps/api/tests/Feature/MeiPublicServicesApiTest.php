<?php

namespace Tests\Feature;

use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Enums\TenantRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MeiPublicServicesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgmei_consult_requires_calendar_year_without_leaking_tenant(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        $client = Client::factory()->forTenant($tenant)->create();
        config(['fiscal_monitoring.enabled' => true]);
        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/pgmei/consult', [
            'client_ids' => [$client->id],
            'calendar_year' => 2025,
            'confirmed' => true,
        ])->assertCreated()
            ->assertJsonPath('calendar_year', 2025)
            ->assertJsonMissingPath('year')
            ->assertJsonMissingPath('data.0.tenant_id');

        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }

    public function test_dasn_history_preserves_summary_coverage_and_tenant_scope(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        $client = Client::factory()->forTenant($tenant)->create();
        $attempt = $this->dasnAttempt($tenant, $client);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history?calendar_year=2025')
            ->assertOk()
            ->assertJsonPath('data.coverage', 'SUMMARY')
            ->assertJsonPath('data.declarations.0.calendar_year', 2025)
            ->assertJsonPath('data.declarations.0.pending', true)
            ->assertJsonPath('data.declarations.0.declaration_type', 'Original')
            ->assertJsonPath('data.declarations.0.special_situation', 'Extinção')
            ->assertJsonPath('data.declarations.0.special_situation_date', '2026-05-20')
            ->assertJsonPath('data.declarations.0.receipt_available', false)
            ->assertJsonPath('data.pending_years.0', 2025)
            ->assertJsonCount(1, 'data.pending_years')
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonMissingPath('data.attempt.tenant_id')
            ->assertJsonMissingPath('data.attempt.result_payload_encrypted');

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.pending_years.0', 2024)
            ->assertJsonPath('data.pending_years.1', 2025)
            ->assertJsonCount(2, 'data.pending_years');

        $queued = MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'dasnsimei.consultimadecrec',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Queued,
            'idempotency_key' => 'dasn:'.str_repeat('q', 12),
            'request_fingerprint' => str_repeat('c', 64),
        ]);

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history?calendar_year=2025')
            ->assertOk()
            ->assertJsonPath('data.coverage', 'SUMMARY')
            ->assertJsonPath('data.declarations.0.calendar_year', 2025)
            ->assertJsonPath('data.attempt.id', $queued->id)
            ->assertJsonPath('data.attempt.status', 'QUEUED');

        $otherClient = Client::factory()->forTenant(Tenant::factory()->create())->create();
        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$otherClient->id.'/history')
            ->assertNotFound();
    }

    public function test_dasn_consult_validates_whole_batch_before_dispatch(): void
    {
        [$user, $tenant] = $this->actor(TenantRole::TenantUser);
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant(Tenant::factory()->create())->create();
        config(['fiscal_monitoring.enabled' => true]);
        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/dasn-simei/consult', [
            'client_ids' => [$client->id, $otherClient->id],
            'calendar_year' => 2025,
            'confirmed' => true,
        ])->assertUnprocessable();
        Queue::assertNothingPushed();

        $this->postJson('/api/v1/fiscal/simples-mei/dasn-simei/consult', [
            'client_ids' => [$client->id],
            'calendar_year' => 2025,
            'include_full_receipt' => false,
            'confirmed' => true,
        ])->assertCreated()
            ->assertJsonPath('enqueued_count', 1)
            ->assertJsonMissingPath('data.0.tenant_id')
            ->assertJsonMissingPath('data.0.idempotency_key');
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }

    public function test_das_preflight_hides_cross_tenant_client(): void
    {
        [$user] = $this->actor(TenantRole::TenantAdmin);
        $otherClient = Client::factory()->forTenant(Tenant::factory()->create())->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/pgmei/das/preflight', [
            'client_id' => $otherClient->id,
            'competencies' => ['2025-01'],
            'output_format' => 'PDF',
            'idempotency_key' => 'das-cross-tenant',
        ])->assertNotFound();
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role)->create();

        return [$user, $tenant];
    }

    private function dasnAttempt(Tenant $tenant, Client $client): MeiAutomationAttempt
    {
        return MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'dasnsimei.consultimadecrec',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Succeeded,
            'idempotency_key' => 'dasn:'.str_repeat('a', 12),
            'request_fingerprint' => str_repeat('b', 64),
            'result_payload_encrypted' => [
                'coverage' => 'SUMMARY',
                'declarations' => [[
                    'calendar_year' => 2025,
                    'status' => 'NÃO APRESENTADA',
                    'transmitted_at' => null,
                    'declaration_type' => 'Original',
                    'special_situation' => 'Extinção',
                    'special_situation_date' => '2026-05-20',
                    'pending' => true,
                    'coverage' => 'SUMMARY',
                    'receipt_available' => false,
                    'receipt_artifact_id' => null,
                ], [
                    'calendar_year' => 2024,
                    'status' => 'NÃO APRESENTADA',
                    'transmitted_at' => null,
                    'declaration_type' => 'Original',
                    'special_situation' => null,
                    'special_situation_date' => null,
                    'pending' => true,
                    'coverage' => 'SUMMARY',
                    'receipt_available' => false,
                    'receipt_artifact_id' => null,
                ]],
                'parser_version' => 'fixture-v1',
                'portal_version' => 'fixture-v1',
            ],
        ]);
    }
}
