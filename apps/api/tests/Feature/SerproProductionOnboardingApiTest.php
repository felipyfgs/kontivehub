<?php

namespace Tests\Feature;

use App\Enums\SerproProductionOnboardingStatus;
use App\Enums\TenantRole;
use App\Models\SerproProductionOnboarding;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class SerproProductionOnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_admin_reads_the_selected_tenant_onboarding_envelope(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        $state = SerproProductionOnboarding::factory()->create([
            'tenant_id' => $tenant->id,
            'actor_user_id' => $actor->id,
            'status' => SerproProductionOnboardingStatus::ActionRequired,
            'consumer_key_hint' => '****1234',
            'contractor_cnpj_masked' => '1234******1234',
            'required_actions' => ['review_authorization'],
        ]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/serpro/production-onboarding')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.onboarding.id', $state->id)
            ->assertJsonPath('data.onboarding.status', 'ACTION_REQUIRED')
            ->assertJsonPath('data.onboarding.hints.consumer_key_hint', '****1234')
            ->assertJsonPath('data.onboarding.required_actions.0', 'review_authorization')
            ->assertJsonStructure([
                'data' => [
                    'consent' => ['version', 'text', 'text_sha256'],
                ],
            ])
            ->assertJsonMissingPath('data.onboarding.idempotency_key');
    }

    public function test_missing_tenant_context_and_tenant_permission_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $globalActor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($globalActor);

        $this->getJson('/api/v1/platform/serpro/production-onboarding')
            ->assertConflict()
            ->assertExactJson([
                'message' => 'Selecione um escritório ativo para ativar SERPRO em produção.',
                'code' => 'tenant_context_required',
            ]);

        $limitedActor = User::factory()
            ->asPlatformAdmin()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        Sanctum::actingAs($limitedActor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($limitedActor);

        $this->post('/api/v1/platform/serpro/production-onboarding', [
            ...$this->validPayload(),
            'certificate' => $this->certificate(),
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Você não possui permissão para gerenciar credenciais deste tenant.',
                'code' => 'tenant_permission_denied',
            ]);
    }

    public function test_activation_remains_disabled_and_rejects_technical_fields(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $this->post('/api/v1/platform/serpro/production-onboarding', [
            ...$this->validPayload(),
            'certificate' => $this->certificate(),
            'tenant_id' => $tenant->id,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->post('/api/v1/platform/serpro/production-onboarding', [
            ...$this->validPayload(),
            'certificate' => $this->certificate(),
        ], ['Accept' => 'application/json'])
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Ativação simplificada SERPRO está desabilitada para este tenant.',
                'code' => 'feature_disabled',
            ]);

        $this->assertDatabaseCount('serpro_production_onboardings', 0);
        $this->assertDatabaseCount('serpro_credential_versions', 0);
        $this->assertDatabaseCount('serpro_rollout_approvals', 0);
    }

    public function test_non_platform_admin_cannot_access_production_onboarding(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/serpro/production-onboarding')->assertForbidden();
        $this->postJson('/api/v1/platform/serpro/production-onboarding')->assertForbidden();
    }

    /** @return array<string, mixed> */
    private function validPayload(): array
    {
        return [
            'consumer_key' => 'consumer-key',
            'consumer_secret' => 'consumer-secret',
            'certificate_password' => 'certificate-password',
            'consent_granted' => true,
        ];
    }

    private function certificate(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('contractor.pfx', 'not-a-real-pfx');
    }
}
