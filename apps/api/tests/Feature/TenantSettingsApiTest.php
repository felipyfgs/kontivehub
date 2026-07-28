<?php

namespace Tests\Feature;

use App\Enums\TenantCredentialPurpose;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantInstitutionalProfile;
use App\Models\TenantMonitorSchedulePolicy;
use App\Models\TenantTechnicalConsent;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_only_current_tenant_settings_without_secret_material(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create([
            'legal_name' => 'Escritório correto',
            'institutional_email' => 'correto@example.test',
        ]);
        TenantInstitutionalProfile::factory()->forTenant($otherTenant)->create([
            'legal_name' => 'Outro escritório',
            'institutional_email' => 'outro@example.test',
        ]);
        TenantTechnicalConsent::factory()->forTenant($tenant)->byUser($viewer)->create();
        $credential = TenantCredential::factory()->certificate()->forTenant($tenant)->create();
        TenantCredentialPurposeLink::factory()
            ->forCredential($credential)
            ->serproTermSigning()
            ->create();
        TenantCredential::factory()->certificate()->forTenant($otherTenant)->create([
            'subject_name' => 'CREDENCIAL DE OUTRO TENANT',
        ]);
        $this->authenticate($viewer);

        $response = $this->getJson('/api/v1/tenant/settings')
            ->assertOk()
            ->assertJsonPath('data.profile.legal_name', 'Escritório correto')
            ->assertJsonPath('data.profile.institutional_email', 'correto@example.test')
            ->assertJsonPath('data.consent.requires_consent', false)
            ->assertJsonPath('data.certificate.id', $credential->id)
            ->assertJsonPath(
                'data.purpose_links.0.purpose',
                TenantCredentialPurpose::SerproTermSigning->value,
            )
            ->assertJsonMissingPath('data.certificate.vault_object_id')
            ->assertJsonMissing(['Outro escritório', 'CREDENCIAL DE OUTRO TENANT']);

        $this->assertArrayNotHasKey('vault_object_id', $response->json('data.certificate'));

        $this->getJson("/api/v1/tenant/settings?tenant_id={$otherTenant->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_profile_update_validates_authorizes_and_rejects_client_tenant_scope(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $profile = TenantInstitutionalProfile::factory()->forTenant($tenant)->incomplete()->create();
        $this->authenticate($admin);

        $this->patchJson('/api/v1/tenant/settings/profile', [
            'institutional_email' => 'não-é-email',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('institutional_email');

        $this->patchJson('/api/v1/tenant/settings/profile', [
            'tenant_id' => $otherTenant->id,
            'legal_name' => 'Escopo injetado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->patchJson('/api/v1/tenant/settings/profile', [
            'cnpj' => '11222333000181',
            'legal_name' => 'Kontive Contabilidade',
            'institutional_email' => 'contato@example.test',
            'institutional_phone' => '+55 11 3000-1000',
        ])->assertOk()
            ->assertJsonPath('data.profile.id', $profile->id)
            ->assertJsonPath('data.profile.legal_name', 'Kontive Contabilidade')
            ->assertJsonPath('data.profile.is_complete', true)
            ->assertJsonPath('data.cnpj_changed', false);

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);
        $this->patchJson('/api/v1/tenant/settings/profile', [
            'legal_name' => 'Bloqueado',
        ])->assertForbidden();

        $this->assertDatabaseHas('tenant_institutional_profiles', [
            'id' => $profile->id,
            'legal_name' => 'Kontive Contabilidade',
        ]);
    }

    public function test_admin_grants_and_revokes_consent_with_stable_errors(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $otherConsent = TenantTechnicalConsent::factory()->forTenant($otherTenant)->create();
        $this->authenticate($admin);

        $consentId = (int) $this->postJson('/api/v1/tenant/settings/consent', [
            'accepted' => true,
        ])->assertCreated()
            ->assertJsonPath('data.active', true)
            ->assertJsonPath('data.version_code', TenantTechnicalConsent::VERSION_CERTIFICATE_V1)
            ->json('data.id');

        $this->getJson('/api/v1/tenant/settings/consent')
            ->assertOk()
            ->assertJsonPath('data.requires_consent', false)
            ->assertJsonPath('data.active_consent.id', $consentId);

        $this->postJson('/api/v1/tenant/settings/consent/revoke')
            ->assertOk()
            ->assertJsonPath('data.id', $consentId)
            ->assertJsonPath('data.active', false);

        $this->postJson('/api/v1/tenant/settings/consent/revoke')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_consent_not_found');

        $this->assertDatabaseHas('tenant_technical_consents', [
            'id' => $otherConsent->id,
            'tenant_id' => $otherTenant->id,
            'revoked_at' => null,
        ]);
    }

    public function test_monitor_schedules_preserve_catalog_validation_permissions_and_tenant_isolation(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $otherPolicy = TenantMonitorSchedulePolicy::factory()->forTenant($otherTenant)->create([
            'monitor_key' => 'sitfis',
            'day_of_month' => 4,
        ]);
        $this->authenticate($admin);

        $this->getJson('/api/v1/tenant/settings/monitor-schedules')
            ->assertOk()
            ->assertJsonCount(8, 'data')
            ->assertJsonPath('data.0.monitor_key', 'sitfis')
            ->assertJsonPath('data.0.monitor_label', 'Situação fiscal');

        $this->putJson('/api/v1/tenant/settings/monitor-schedules/sitfis', [
            'day_of_month' => 12,
        ])->assertOk()
            ->assertJsonPath('data.monitor_key', 'sitfis')
            ->assertJsonPath('data.day_of_month', 12)
            ->assertJsonPath('data.is_default', false);

        $this->putJson('/api/v1/tenant/settings/monitor-schedules/sitfis', [
            'day_of_month' => 29,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('day_of_month');

        $this->putJson('/api/v1/tenant/settings/monitor-schedules/desconhecido', [
            'day_of_month' => 10,
        ])->assertNotFound();

        $this->putJson('/api/v1/tenant/settings/monitor-schedules/sitfis', [
            'tenant_id' => $otherTenant->id,
            'day_of_month' => 20,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->assertDatabaseHas('tenant_monitor_schedule_policies', [
            'tenant_id' => $tenant->id,
            'monitor_key' => 'sitfis',
            'day_of_month' => 12,
        ]);
        $this->assertDatabaseHas('tenant_monitor_schedule_policies', [
            'id' => $otherPolicy->id,
            'day_of_month' => 4,
        ]);

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);
        $this->putJson('/api/v1/tenant/settings/monitor-schedules/sitfis', [
            'day_of_month' => 18,
        ])->assertForbidden();
    }

    public function test_certificate_failures_are_stable_and_preserve_previous_active_certificate(): void
    {
        [$admin, $tenant] = $this->admin();
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create();
        $this->authenticate($admin);

        $password = 'senha-que-nao-pode-ser-exposta';
        $this->post('/api/v1/tenant/settings/certificate', [
            'pfx' => UploadedFile::fake()->create(
                'certificate.pfx',
                1,
                'application/x-pkcs12',
            ),
            'password' => $password,
            'consent_accepted' => true,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_certificate_mutation_failed')
            ->assertJsonMissing([$password]);

        $this->assertDatabaseCount('tenant_credentials', 0);

        $previous = TenantCredential::factory()->certificate()->forTenant($tenant)->create();
        $this->post('/api/v1/tenant/settings/certificate/replace', [
            'pfx' => UploadedFile::fake()->create(
                'replacement.pfx',
                1,
                'application/x-pkcs12',
            ),
            'password' => $password,
            'consent_accepted' => true,
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_certificate_mutation_failed')
            ->assertJsonPath('previous_preserved', true)
            ->assertJsonMissing([$password]);

        $this->assertDatabaseHas('tenant_credentials', [
            'id' => $previous->id,
            'tenant_id' => $tenant->id,
            'status' => 'ACTIVE',
        ]);

        $auditContexts = AuditLog::query()
            ->whereIn('action', ['tenant_credential.activate', 'tenant_credential.replace'])
            ->pluck('context')
            ->map(fn ($context): string => json_encode(
                $context,
                JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ))
            ->implode(' ');
        $this->assertStringNotContainsString($password, $auditContexts);
    }

    /** @return array{User, Tenant} */
    private function actor(string $permissionProfile = 'operator'): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, $permissionProfile)
            ->create();

        return [$user, $tenant];
    }

    /** @return array{User, Tenant} */
    private function admin(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();

        return [$admin, $tenant];
    }

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
