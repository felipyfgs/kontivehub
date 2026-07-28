<?php

namespace Tests\Feature;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\SubscriptionPlan;
use App\Enums\TenantLifecycleStatus;
use App\Enums\TenantRole;
use App\Models\AccountActivation;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Activation\ActivationCredentialService;
use App\Services\Auth\RecentPasswordConfirmationGate;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class PlatformTenantAdminApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_list_filters_declared_fields_and_eager_loads_latest_activation(): void
    {
        $actor = $this->platformAdmin();
        $pending = Tenant::query()->create([
            'name' => 'Escritório pendente',
            'slug' => 'escritorio-pendente',
            'is_active' => false,
            'lifecycle_status' => TenantLifecycleStatus::PendingActivation,
        ]);
        $recipient = User::factory()->create([
            'email' => 'pending@example.test',
            'is_active' => false,
            'password_change_required' => true,
        ]);
        $membership = TenantMembership::query()->create([
            'tenant_id' => $pending->id,
            'user_id' => $recipient->id,
            'role' => TenantRole::TenantAdmin,
            'is_active' => false,
        ]);
        $previous = $this->activation($pending, $membership, $recipient, generation: 1, revoked: true);
        $latest = $this->activation($pending, $membership, $recipient, generation: 2);
        $suspended = Tenant::query()->create([
            'name' => 'Escritório suspenso',
            'slug' => 'escritorio-suspenso',
            'is_active' => false,
            'lifecycle_status' => TenantLifecycleStatus::Suspended,
        ]);

        Sanctum::actingAs($actor);

        $activationQueries = 0;
        DB::listen(static function (QueryExecuted $query) use (&$activationQueries): void {
            if (str_contains($query->sql, 'account_activations')) {
                $activationQueries++;
            }
        });

        $this->getJson('/api/v1/platform/tenants/admin?lifecycle_status=pending_activation')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $pending->id)
            ->assertJsonPath('data.0.lifecycle_status', TenantLifecycleStatus::PendingActivation->value)
            ->assertJsonPath('data.0.activation.id', $latest->id)
            ->assertJsonPath('data.0.activation.generation', 2)
            ->assertJsonMissing(['id' => $previous->id]);

        self::assertSame(1, $activationQueries);

        $this->getJson('/api/v1/platform/tenants/admin?lifecycle_status=')
            ->assertOk()
            ->assertJsonMissing(['id' => $suspended->id]);

        $this->getJson('/api/v1/platform/tenants/admin?lifecycle_status=suspended')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $suspended->id);

        $this->getJson('/api/v1/platform/tenants/admin?state=ACTIVE')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('state');
    }

    public function test_pending_tenant_creation_normalizes_blank_cnpj_and_handles_idempotency(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $payload = $this->creationPayload();

        $created = $this->postJson('/api/v1/platform/tenants', $payload)
            ->assertCreated()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.tenant.name', 'Escritório Exemplo')
            ->assertJsonPath('data.tenant.profile.cnpj', null)
            ->assertJsonPath('data.tenant.lifecycle_status', TenantLifecycleStatus::PendingActivation->value)
            ->assertJsonPath('data.tenant.subscription.plan', SubscriptionPlan::Starter->value)
            ->assertJsonPath('data.credential_delivery', 'delivered')
            ->assertJsonPath('data.method', ActivationMethod::ManualLink->value)
            ->assertJsonStructure(['data' => [
                'activation_url',
                'expires_at',
                'tenant' => ['activation'],
            ]]);

        $tenantId = (int) $created->json('data.tenant.id');
        $this->assertDatabaseHas('tenant_institutional_profiles', [
            'tenant_id' => $tenantId,
            'cnpj' => null,
        ]);
        $this->assertDatabaseHas('tenant_memberships', [
            'tenant_id' => $tenantId,
            'role' => TenantRole::TenantAdmin->value,
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/platform/tenants', $payload)
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.tenant.id', $tenantId)
            ->assertJsonPath('data.credential_delivery', 'regeneration_required')
            ->assertJsonMissingPath('data.activation_url')
            ->assertJsonMissingPath('data.temporary_password');

        $this->getJson("/api/v1/platform/tenants/{$tenantId}")
            ->assertOk()
            ->assertJsonPath('data.id', $tenantId)
            ->assertJsonPath('data.lifecycle_status', TenantLifecycleStatus::PendingActivation->value)
            ->assertJsonPath('data.profile.legal_name', 'Escritório Exemplo Contabilidade Ltda.')
            ->assertJsonPath('data.first_admin.email', 'first.admin@example.test')
            ->assertJsonStructure(['data' => [
                'id',
                'name',
                'slug',
                'lifecycle_status',
                'profile',
                'subscription',
                'first_admin',
                'activation',
            ]]);

        $this->postJson('/api/v1/platform/tenants', [
            ...$payload,
            'name' => 'Payload divergente',
        ])->assertConflict()
            ->assertExactJson([
                'message' => 'Chave de idempotência já usada com payload diferente.',
                'code' => 'idempotency_payload_mismatch',
            ]);
    }

    public function test_creation_rejects_unknown_nested_fields(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $payload = $this->creationPayload();
        $payload['profile']['tax_regime'] = 'SIMPLES_NACIONAL';

        $this->postJson('/api/v1/platform/tenants', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('profile');

        $this->assertDatabaseMissing('tenant_creation_idempotency', [
            'idempotency_key' => $payload['idempotency_key'],
        ]);
    }

    public function test_activation_regeneration_revokes_previous_generation(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $created = $this->postJson('/api/v1/platform/tenants', $this->creationPayload())
            ->assertCreated();

        $tenantId = (int) $created->json('data.tenant.id');
        $previousId = (int) $created->json('data.tenant.activation.id');

        $regenerated = $this->postJson(
            "/api/v1/platform/tenants/{$tenantId}/activation/regenerate",
            ['method' => ActivationMethod::TemporaryPassword->value],
        )->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.credential_delivery', 'delivered')
            ->assertJsonPath('data.method', ActivationMethod::TemporaryPassword->value)
            ->assertJsonPath('data.activation.generation', 2)
            ->assertJsonStructure(['data' => ['temporary_password']])
            ->assertJsonMissingPath('data.activation_url');

        $newId = (int) $regenerated->json('data.activation.id');
        self::assertNotSame($previousId, $newId);

        $this->assertDatabaseHas('account_activations', [
            'id' => $previousId,
        ]);
        self::assertNotNull(AccountActivation::query()->findOrFail($previousId)->revoked_at);
        $this->assertDatabaseHas('account_activations', [
            'id' => $newId,
            'tenant_id' => $tenantId,
            'generation' => 2,
            'revoked_at' => null,
        ]);
    }

    public function test_first_admin_correction_replaces_pending_identity_and_preserves_it_on_conflict(): void
    {
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $created = $this->postJson('/api/v1/platform/tenants', $this->creationPayload())
            ->assertCreated();

        $tenantId = (int) $created->json('data.tenant.id');
        $oldMembershipId = (int) $created->json('data.tenant.first_admin.membership_id');
        $oldUserId = (int) $created->json('data.tenant.first_admin.user_id');
        $oldActivationId = (int) $created->json('data.tenant.activation.id');
        $unavailable = User::factory()->create(['email' => 'unavailable@example.test']);

        $this->patchJson("/api/v1/platform/tenants/{$tenantId}/first-admin", [
            'name' => 'Não deve persistir',
            'email' => $unavailable->email,
            'method' => ActivationMethod::ManualLink->value,
        ])->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Não foi possível concluir com o e-mail informado.',
                'code' => 'email_unavailable',
            ]);

        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $oldMembershipId,
            'user_id' => $oldUserId,
        ]);
        $this->assertDatabaseHas('account_activations', [
            'id' => $oldActivationId,
            'revoked_at' => null,
        ]);

        $corrected = $this->patchJson("/api/v1/platform/tenants/{$tenantId}/first-admin", [
            'name' => 'Nova Administradora',
            'email' => 'NEW.ADMIN@EXAMPLE.TEST',
            'method' => ActivationMethod::ManualLink->value,
        ])->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('data.first_admin.name', 'Nova Administradora')
            ->assertJsonPath('data.first_admin.email', 'new.admin@example.test')
            ->assertJsonPath('data.activation.generation', 1)
            ->assertJsonPath('data.credential_delivery', 'delivered')
            ->assertJsonStructure(['data' => ['activation_url']]);

        $newMembershipId = (int) $corrected->json('data.first_admin.membership_id');
        $newUserId = (int) $corrected->json('data.first_admin.user_id');

        self::assertNotSame($oldMembershipId, $newMembershipId);
        self::assertNotSame($oldUserId, $newUserId);
        $this->assertDatabaseMissing('tenant_memberships', ['id' => $oldMembershipId]);
        $this->assertDatabaseMissing('users', ['id' => $oldUserId]);
        $this->assertDatabaseMissing('account_activations', ['id' => $oldActivationId]);
        $this->assertDatabaseHas('tenant_memberships', [
            'id' => $newMembershipId,
            'tenant_id' => $tenantId,
            'user_id' => $newUserId,
            'role' => TenantRole::TenantAdmin->value,
            'is_active' => false,
        ]);
    }

    public function test_mutations_require_platform_authorization_and_recent_password(): void
    {
        $tenant = Tenant::factory()->create();
        $regularUser = User::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($regularUser);

        $this->postJson('/api/v1/platform/tenants', $this->creationPayload())
            ->assertForbidden()
            ->assertExactJson([
                'message' => 'Ação restrita a administradores da plataforma.',
            ]);

        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/platform/tenants', $this->creationPayload())
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');
    }

    private function platformAdmin(): User
    {
        $defaultTenant = Tenant::factory()->create([
            'name' => 'Tenant padrão',
            'slug' => 'tenant-padrao',
        ]);

        return User::factory()->asPlatformAdmin($defaultTenant->id)->create();
    }

    /**
     * @return array{
     *     name: string,
     *     profile: array{
     *         cnpj: string,
     *         legal_name: string,
     *         institutional_email: string,
     *         institutional_phone: string
     *     },
     *     plan: string,
     *     admin_name: string,
     *     admin_email: string,
     *     method: string,
     *     idempotency_key: string
     * }
     */
    private function creationPayload(): array
    {
        return [
            'name' => 'Escritório Exemplo',
            'profile' => [
                'cnpj' => '',
                'legal_name' => 'Escritório Exemplo Contabilidade Ltda.',
                'institutional_email' => 'office@example.test',
                'institutional_phone' => '+55 11 99999-0000',
            ],
            'plan' => SubscriptionPlan::Starter->value,
            'admin_name' => 'Primeira Administradora',
            'admin_email' => 'first.admin@example.test',
            'method' => ActivationMethod::ManualLink->value,
            'idempotency_key' => 'tenant-admin-api-test-0001',
        ];
    }

    private function activation(
        Tenant $tenant,
        TenantMembership $membership,
        User $recipient,
        int $generation,
        bool $revoked = false,
    ): AccountActivation {
        $credentials = app(ActivationCredentialService::class);
        $issued = $credentials->issueSecret(ActivationMethod::ManualLink);

        return AccountActivation::query()->create([
            'purpose' => ActivationPurpose::TenantFirstAdmin,
            'method' => ActivationMethod::ManualLink,
            'user_id' => $recipient->id,
            'tenant_id' => $tenant->id,
            'tenant_membership_id' => $membership->id,
            'platform_membership_id' => null,
            'email_normalized' => $recipient->email,
            'secret_hash' => $issued['hash'],
            'expires_at' => now()->addDays(7),
            'consumed_at' => null,
            'revoked_at' => $revoked ? now() : null,
            'generation' => $generation,
            'created_by_user_id' => null,
        ]);
    }
}
