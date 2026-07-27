<?php

namespace Tests\Feature;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TenantRole;
use App\Enums\TenantSerproOnboardingStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Tenant;
use App\Models\TenantSerproOnboardingState;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantOnboardingStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_upload_and_replacement_require_consent_in_the_same_request(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/tenant/settings/certificate', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_accepted']);
        $this->postJson('/api/v1/tenant/settings/certificate/replace', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['consent_accepted']);
    }

    public function test_status_exposes_progress_modules_procuracoes_and_initial_collection_without_crossing_tenants(): void
    {
        config()->set('fiscal.profile', 'dev');
        config()->set('fiscal.kill_switch', false);

        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->forTenant($tenant)->create(['is_active' => true]);
        $otherClient = Client::factory()->forTenant($otherTenant)->create(['is_active' => true]);

        TenantSerproOnboardingState::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => TenantSerproOnboardingStatus::Ready,
            'last_step' => 'ready',
            'ready_at' => now(),
            'metadata' => ['initial_collection_queued_at' => now()->toIso8601String()],
        ]);
        ClientProcuracaoSync::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'last_verified_at' => now(),
        ]);
        ClientProcuracaoSync::query()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Failed,
        ]);

        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/tenant/settings/onboarding-status')
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.stage', 'PRONTO')
            ->assertJsonCount(10, 'data.modules')
            ->assertJsonPath('data.procuracoes.total_clients', 1)
            ->assertJsonPath('data.procuracoes.by_status.authorized', 1)
            ->assertJsonPath('data.procuracoes.verified', 1)
            ->assertJsonPath('data.initial_collection.runs_total', 0);
    }
}
