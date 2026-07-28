<?php

namespace Tests\Feature;

use App\Actions\Tenant\UploadTenantSerproTermAction;
use App\DTO\Tenant\TenantSerproTermUploadData;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Enums\TenantRole;
use App\Enums\TermoAuthorizationState;
use App\Exceptions\TenantSerproAuthorizationApiException;
use App\Models\Client;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantSerproAuthorizationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('serpro.default_environment', SerproEnvironment::Trial->value);
        config()->set('serpro.termo_destination_cnpj', '11222333000181');
        config()->set('serpro.termo_destination_name', 'CONTRATANTE TESTE');
    }

    public function test_term_upload_action_rejects_missing_xml_sources_before_service_call(): void
    {
        try {
            app(UploadTenantSerproTermAction::class)(new TenantSerproTermUploadData(
                environment: SerproEnvironment::Trial,
                xml: null,
                filePath: null,
                actorUserId: 1,
            ));
            self::fail('A action deveria rejeitar a ausência das duas fontes de XML.');
        } catch (TenantSerproAuthorizationApiException $exception) {
            self::assertSame('tenant_serpro_authorization_failed', $exception->stableCode());
            self::assertSame('Informe o XML ou um arquivo do Termo.', $exception->safeMessage());
        }
    }

    public function test_viewer_reads_only_current_tenant_authorization_with_masked_identity(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        $otherAuthorization = $this->authorization($otherTenant, '99888777000166');
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/serpro-authorization?environment=trial')
            ->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.environment', SerproEnvironment::Trial->value)
            ->assertJsonPath('data.author_identity_masked', '**********0000')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'tenant_id',
                    'environment',
                    'status',
                    'author_identity_masked',
                    'actions_required',
                ],
                'platform_health',
                'onboarding',
                'actionable',
                'platform_available',
                'term_representation_strategy',
            ])
            ->assertJsonMissingPath('data.termo_vault_object_id')
            ->assertJsonMissingPath('data.procurador_token_vault_object_id')
            ->assertJsonMissing([$otherAuthorization->id, '**********0166']);

        $this->getJson("/api/v1/tenant/serpro-authorization?tenant_id={$otherTenant->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/tenant/serpro-authorization/author', [
            'author_identity_type' => AuthorIdentityType::Cnpj->value,
            'author_identity' => '11222333000181',
        ])->assertForbidden();
    }

    public function test_admin_configures_author_with_validation_and_stable_failure_code(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $this->authenticate($admin);

        $this->postJson('/api/v1/tenant/serpro-authorization/author', [
            'environment' => 'trial',
            'author_identity_type' => AuthorIdentityType::Cnpj->value,
            'author_identity' => '123',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_serpro_authorization_failed');

        $this->postJson('/api/v1/tenant/serpro-authorization/author', [
            'tenant_id' => $otherTenant->id,
            'author_identity_type' => AuthorIdentityType::Cnpj->value,
            'author_identity' => '11222333000181',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/tenant/serpro-authorization/author', [
            'environment' => 'trial',
            'author_identity_type' => AuthorIdentityType::Cnpj->value,
            'author_identity' => '11222333000181',
            'author_name' => 'Autor do Escritório',
            'certificate_mode' => AuthorCertificateMode::ExternalSignature->value,
        ])->assertOk()
            ->assertJsonPath('data.tenant_id', $tenant->id)
            ->assertJsonPath('data.author_identity_masked', '**********0181')
            ->assertJsonPath('data.author_name', 'Autor do Escritório')
            ->assertJsonPath('data.status', SerproAuthorizationStatus::PendingTerm->value);
    }

    public function test_term_draft_download_requires_recent_password_and_never_exposes_vault_reference(): void
    {
        [$admin, $tenant] = $this->admin();
        $this->authenticate($admin);
        $this->postJson('/api/v1/tenant/serpro-authorization/author', [
            'author_identity_type' => AuthorIdentityType::Cnpj->value,
            'author_identity' => '11222333000181',
            'author_name' => 'Autor do Escritório',
        ])->assertOk();

        $draftSha = (string) $this->postJson('/api/v1/tenant/serpro-authorization/termo/draft', [
            'vigencia' => now()->addYear()->toDateString(),
        ])->assertCreated()
            ->assertJsonPath('data.has_termo', false)
            ->assertJsonMissingPath('data.termo_vault_object_id')
            ->json('draft_sha256');

        $this->getJson('/api/v1/tenant/serpro-authorization/termo/draft')
            ->assertForbidden()
            ->assertJsonPath('code', 'password_confirmation_required');

        app(RecentPasswordConfirmationGate::class)->markConfirmed($admin);
        $download = $this->get('/api/v1/tenant/serpro-authorization/termo/draft', [
            'Accept' => 'application/xml',
        ])->assertOk()
            ->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame($draftSha, hash('sha256', $download->getContent()));
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'serpro.authorization.termo_draft_download',
        ]);
    }

    public function test_proxy_power_list_is_paginated_and_isolated_and_manual_override_is_rejected(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $authorization = $this->authorization($tenant, '11222333000181');
        $otherAuthorization = $this->authorization($otherTenant, '99888777000166');
        $ownPower = $this->power($tenant, $client, $authorization, 'POWER_OWN');
        $this->power($otherTenant, $otherClient, $otherAuthorization, 'POWER_OTHER');
        $this->authenticate($admin);

        $this->getJson('/api/v1/tenant/serpro-authorization/proxy-powers?per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $ownPower->id)
            ->assertJsonPath('data.0.power_code', 'POWER_OWN')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissing(['POWER_OTHER']);

        $this->getJson('/api/v1/tenant/serpro-authorization/proxy-powers?sort=unknown')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('sort');

        $this->postJson('/api/v1/tenant/serpro-authorization/proxy-powers')
            ->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_proxy_power_manual_override_rejected');
    }

    public function test_sync_and_eligibility_reject_cross_tenant_client_before_external_work(): void
    {
        [$admin] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $this->authenticate($admin);

        $this->postJson('/api/v1/tenant/serpro-authorization/proxy-powers/sync', [
            'client_id' => $otherClient->id,
        ])->assertNotFound();

        $this->postJson('/api/v1/tenant/serpro-authorization/eligibility', [
            'client_id' => $otherClient->id,
            'solution_code' => 'SOLUTION',
            'service_code' => 'SERVICE',
            'operation_code' => 'OPERATION',
        ])->assertNotFound();

        $this->assertDatabaseCount('client_procuracao_syncs', 0);
        $this->assertDatabaseCount('serpro_operations', 0);
    }

    private function authorization(
        Tenant $tenant,
        string $identity,
    ): TenantSerproAuthorization {
        return TenantSerproAuthorization::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::PendingTerm,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => $identity,
            'author_name' => 'Autor',
            'certificate_mode' => AuthorCertificateMode::ExternalSignature,
            'termo_authorization_state' => TermoAuthorizationState::Draft,
        ]);
    }

    private function power(
        Tenant $tenant,
        Client $client,
        TenantSerproAuthorization $authorization,
        string $powerCode,
    ): TaxProxyPower {
        return TaxProxyPower::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'tenant_serpro_authorization_id' => $authorization->id,
            'environment' => SerproEnvironment::Trial->value,
            'author_identity' => $authorization->author_identity,
            'contributor_cnpj' => '44555666000177',
            'system_code' => 'SYSTEM',
            'service_code' => 'SERVICE',
            'power_code' => $powerCode,
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'provenance' => 'official_api',
            'status' => TaxProxyPowerStatus::Active,
            'accepted_at' => now(),
            'freshness_checked_at' => now(),
            'verified_at' => now(),
            'last_check_result' => 'ACTIVE',
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(string $permissionProfile = 'operator'): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, $permissionProfile)
            ->create();

        return [$actor, $tenant];
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

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
