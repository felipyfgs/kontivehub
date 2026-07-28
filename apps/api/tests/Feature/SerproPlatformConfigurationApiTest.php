<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\SerproEnvironment;
use App\Models\SerproCredentialVersion;
use App\Models\SerproRolloutApproval;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproRolloutApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use OpenSSLAsymmetricKey;
use OpenSSLCertificate;
use Tests\TestCase;

final class SerproPlatformConfigurationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_credential_version_lifecycle_uses_sanitized_resources_and_owner_approval(): void
    {
        $this->app->instance(SecureObjectStore::class, new InMemorySecureObjectStore);
        $actor = $this->platformAdmin();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $password = 'test-password';
        $response = $this->post('/api/v1/platform/serpro/credential-versions', [
            'environment' => 'TRIAL',
            'pfx' => UploadedFile::fake()->createWithContent(
                'contractor.pfx',
                $this->pfx($password),
            ),
            'password' => $password,
            'consumer_key' => 'consumer-key-for-tests',
            'consumer_secret' => 'consumer-secret-for-tests',
            'notes' => 'Versão de teste.',
        ], ['Accept' => 'application/json']);

        $response->assertCreated()
            ->assertJsonPath('data.environment', 'TRIAL')
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.has_pfx', true)
            ->assertJsonPath('data.has_oauth', true)
            ->assertJsonMissingPath('data.pfx_vault_object_id')
            ->assertJsonMissingPath('data.oauth_vault_object_id');

        $version = SerproCredentialVersion::query()->sole();

        $this->postJson("/api/v1/platform/serpro/credential-versions/{$version->id}/test-connection")
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Não foi possível concluir o teste de conexão.',
                'code' => 'serpro_credential_connection_test_failed',
            ]);

        $this->postJson("/api/v1/platform/serpro/credential-versions/{$version->id}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFIED')
            ->assertJsonMissingPath('data.pfx_vault_object_id');

        $approval = $this->approvedCredentialActivation($version->refresh(), $actor);

        $this->postJson("/api/v1/platform/serpro/credential-versions/{$version->id}/activation", [
            'skip_oauth' => true,
            'approval_id' => $approval->id,
            'reason' => 'Ativação controlada em teste.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.serpro_contract_id', fn (mixed $id): bool => is_int($id) && $id > 0)
            ->assertJsonMissingPath('data.oauth_vault_object_id');

        $this->assertDatabaseHas('serpro_credential_versions', [
            'id' => $version->id,
            'status' => 'ACTIVE',
            'activated_by_user_id' => $actor->id,
        ]);
        $this->assertDatabaseHas('serpro_rollout_approvals', [
            'id' => $approval->id,
            'status' => 'EXECUTED',
        ]);
    }

    public function test_platform_admin_updates_external_gate_and_usage_limits(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);
        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $this->patchJson(
            '/api/v1/platform/serpro/external-gates/OAUTH_ENDPOINT_DIVERGENCE',
            [
                'ticket_ref' => 'SERPRO-123',
                'answer_summary' => 'Endpoint oficial confirmado.',
                'responsible_name' => 'Operações',
                'reference_date' => '2026-07-28',
                'environment' => 'PRODUCTION',
            ],
        )->assertOk()
            ->assertJsonPath('data.kind', 'OAUTH_ENDPOINT_DIVERGENCE')
            ->assertJsonPath('data.status', 'ACCEPTED')
            ->assertJsonPath('data.is_complete', true);

        $this->putJson('/api/v1/platform/serpro/usage-limits', [
            'environment' => 'TRIAL',
            'cycle_start_day' => 21,
            'alert_percent' => 80,
            'global_limit_quantity' => 1000,
            'tenant_limits' => [[
                'tenant_id' => $tenant->id,
                'limit_quantity' => 100,
            ]],
        ])->assertOk()
            ->assertJsonPath('data.config.environment', 'TRIAL')
            ->assertJsonPath('data.config.global_limit_quantity', 1000)
            ->assertJsonPath('data.tenant_limits.0.tenant_id', $tenant->id)
            ->assertJsonPath('data.tenant_limits.0.limit_quantity', 100);

        $this->assertDatabaseHas('serpro_quantity_usage_limits', [
            'environment' => 'TRIAL',
            'global_limit_quantity' => 1000,
        ]);
        $this->assertDatabaseHas('serpro_tenant_quantity_usage_limits', [
            'environment' => 'TRIAL',
            'tenant_id' => $tenant->id,
            'limit_quantity' => 100,
        ]);
    }

    public function test_configuration_boundaries_reject_unknown_fields_and_missing_confirmation(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/platform/serpro/configuration?enviroment=TRIAL')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('enviroment');

        $this->putJson('/api/v1/platform/serpro/usage-limits', [
            'environment' => 'TRIAL',
            'cycle_start_day' => 21,
            'alert_percent' => 80,
            'global_limit_quantity' => 1000,
        ])->assertForbidden()
            ->assertExactJson([
                'message' => 'Operação exige reconfirmação de senha recente.',
                'code' => 'password_confirmation_required',
            ]);

        app(RecentPasswordConfirmationGate::class)->markConfirmed($actor);

        $this->patchJson('/api/v1/platform/serpro/external-gates/UNKNOWN_GATE', [
            'ticket_ref' => 'SERPRO-123',
            'answer_summary' => 'Resposta.',
            'responsible_name' => 'Operações',
            'reference_date' => '2026-07-28',
        ])->assertNotFound()
            ->assertJsonPath('code', 'serpro_external_gate_not_found');

        $this->putJson('/api/v1/platform/serpro/usage-limits', [
            'environment' => 'TRIAL',
            'cycle_start_day' => 21,
            'alert_percent' => 80,
            'tenant_limits' => [
                ['tenant_id' => $tenant->id, 'limit_quantity' => 100],
                ['tenant_id' => $tenant->id, 'limit_quantity' => 200],
            ],
            'unlimited' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'tenant_limits.0.tenant_id',
                'tenant_limits.1.tenant_id',
                'unlimited',
            ]);
    }

    public function test_non_platform_admin_cannot_access_global_serpro_configuration(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/v1/platform/serpro/configuration')->assertForbidden();
        $this->postJson('/api/v1/platform/serpro/credential-versions')->assertForbidden();
        $this->putJson('/api/v1/platform/serpro/usage-limits')->assertForbidden();
    }

    private function approvedCredentialActivation(
        SerproCredentialVersion $version,
        User $actor,
    ): SerproRolloutApproval {
        $rollouts = app(SerproRolloutApprovalService::class);
        $start = CarbonImmutable::now()->subMinute();
        $end = CarbonImmutable::now()->addMinutes(30);
        $approval = $rollouts->request(
            action: SerproRolloutApprovalService::ACTION_CREDENTIAL_ACTIVATION,
            subjectType: 'CREDENTIAL_VERSION',
            subjectId: $version->id,
            reason: 'Ativação controlada em teste.',
            requestedByUserId: $actor->id,
            environment: SerproEnvironment::Trial,
            changeWindowStart: $start,
            changeWindowEnd: $end,
            fromHttp: true,
        );

        $rollouts->approve(
            $approval,
            $actor->id,
            passwordRecentlyConfirmed: true,
            reason: 'Ativação controlada em teste.',
            confirmationPhrase: 'CONFIRMO-CREDENTIAL_ACTIVATION',
            changeWindowStart: $start,
            changeWindowEnd: $end,
            fromHttp: true,
        );

        return $approval->refresh();
    }

    private function platformAdmin(): User
    {
        $tenant = Tenant::factory()->create();

        return User::factory()->asPlatformAdmin($tenant->id)->create();
    }

    private function pfx(string $password): string
    {
        $key = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        self::assertInstanceOf(OpenSSLAsymmetricKey::class, $key);

        $csr = openssl_csr_new(
            ['commonName' => '11222333000181'],
            $key,
            ['digest_alg' => 'sha256'],
        );
        self::assertNotFalse($csr);

        $certificate = openssl_csr_sign($csr, null, $key, 365, ['digest_alg' => 'sha256']);
        self::assertInstanceOf(OpenSSLCertificate::class, $certificate);

        $pfx = '';
        self::assertTrue(openssl_pkcs12_export($certificate, $pfx, $key, $password));

        return $pfx;
    }
}

final class InMemorySecureObjectStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    public function put(string $plaintext, array $metadata = []): string
    {
        $id = str_pad((string) (count($this->objects) + 1), 26, '0', STR_PAD_LEFT);
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        return $this->objects[$objectId] ?? throw new \RuntimeException('Objeto não encontrado.');
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return array_key_exists($objectId, $this->objects);
    }
}
