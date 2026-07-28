<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MeiAutomationAttemptApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_exposes_only_sanitized_attempt_from_current_tenant(): void
    {
        [$user, $tenant] = $this->actor();
        $attempt = $this->attempt($tenant);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/fiscal/mei-automation/attempts/'.$attempt->id);

        $response->assertOk()
            ->assertJsonPath('data.provider', 'RECEITA_PORTAL')
            ->assertJsonMissingPath('data.tenant_id')
            ->assertJsonMissingPath('data.idempotency_key')
            ->assertJsonMissingPath('data.request_fingerprint')
            ->assertJsonMissingPath('data.artifacts.0.object_id');
    }

    public function test_cross_tenant_attempt_is_not_found_and_tenant_id_query_is_rejected(): void
    {
        [$user, $tenant] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $otherAttempt = $this->attempt($otherTenant);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/fiscal/mei-automation/attempts/'.$otherAttempt->id)
            ->assertNotFound();
        $this->getJson('/api/v1/fiscal/mei-automation/attempts/'.$otherAttempt->id.'?tenant_id='.$tenant->id)
            ->assertUnprocessable();
    }

    public function test_downloads_vault_artifact_only_from_current_tenant(): void
    {
        [$user, $tenant] = $this->actor();
        $attempt = $this->attempt($tenant);
        $artifact = $attempt->vault_artifacts[0];
        $this->mock(SecureObjectStore::class)
            ->shouldReceive('get')
            ->once()
            ->with($artifact['object_id'], [
                'purpose' => 'MEI_PORTAL_ARTIFACT',
                'tenant_id' => $tenant->id,
                'client_id' => $attempt->client_id,
                'attempt_id' => $attempt->id,
                'artifact_id' => $artifact['id'],
                'content_type' => $artifact['content_type'],
                'sha256' => $artifact['sha256'],
            ])
            ->andReturn('%PDF-1.4 fixture %%EOF');
        Sanctum::actingAs($user);

        $this->get('/api/v1/fiscal/mei-automation/attempts/'.$attempt->id.'/artifacts/'.$artifact['id'].'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
        $this->get('/api/v1/fiscal/mei-automation/attempts/'.$attempt->id.'/artifacts/'.$artifact['id'].'/download?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $otherAttempt = $this->attempt(Tenant::factory()->create());
        $otherArtifact = $otherAttempt->vault_artifacts[0];
        $this->get('/api/v1/fiscal/mei-automation/attempts/'.$otherAttempt->id.'/artifacts/'.$otherArtifact['id'].'/download')
            ->assertNotFound();
    }

    /** @return array{User, Tenant} */
    private function actor(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();

        return [$user, $tenant];
    }

    private function attempt(Tenant $tenant): MeiAutomationAttempt
    {
        $client = Client::factory()->forTenant($tenant)->create();

        return MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'operation_key' => 'pgmei.dividaativa',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Succeeded,
            'idempotency_key' => 'api:'.str_repeat('a', 12),
            'request_fingerprint' => str_repeat('b', 64),
            'safe_metadata' => ['portal_version' => 'fixture'],
            'vault_artifacts' => [[
                'id' => '3dfad6d4-f87c-44da-91eb-1e77cf53dd57',
                'name' => 'das.pdf',
                'content_type' => 'application/pdf',
                'byte_size' => 10,
                'sha256' => str_repeat('c', 64),
                'object_id' => 'opaque-vault-object',
            ]],
        ]);
    }
}
