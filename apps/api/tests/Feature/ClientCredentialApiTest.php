<?php

namespace Tests\Feature;

use App\Contracts\PfxReaderInterface;
use App\Contracts\SecureObjectStore;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

final class ClientCredentialApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_admin_reads_and_activates_a_sanitized_client_credential(): void
    {
        $this->app->instance(SecureObjectStore::class, new ClientCredentialMemoryStore);
        $this->app->instance(PfxReaderInterface::class, new SuccessfulClientPfxReader);

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->forTenant($tenant)->withRoot('11222333')->create();
        Sanctum::actingAs($actor);

        $this->getJson("/api/v1/clients/{$client->id}/credential")
            ->assertOk()
            ->assertExactJson(['data' => null]);

        $this->post("/api/v1/clients/{$client->id}/credential", [
            'pfx' => $this->pfx(),
            'password' => 'certificate-password',
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.holder_cnpj', '11222333000181')
            ->assertJsonMissingPath('data.vault_object_id')
            ->assertJsonMissingPath('data.password');

        $this->getJson("/api/v1/clients/{$client->id}/credential")
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonMissingPath('data.vault_object_id');

        $this->assertDatabaseHas('client_credentials', [
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'status' => 'ACTIVE',
            'holder_cnpj' => '11222333000181',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'credential.activate',
            'result' => 'SUCCESS',
        ]);
    }

    public function test_activation_validates_shape_and_returns_a_stable_sanitized_failure(): void
    {
        $this->app->instance(SecureObjectStore::class, new ClientCredentialMemoryStore);
        $this->app->instance(PfxReaderInterface::class, new FailingClientPfxReader);

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        $client = Client::factory()->forTenant($tenant)->create();
        Sanctum::actingAs($actor);

        $this->postJson("/api/v1/clients/{$client->id}/credential", [
            'tenant_id' => $tenant->id,
            'password' => 'certificate-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson("/api/v1/clients/{$client->id}/credential", [
            'password' => 'certificate-password',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('pfx');

        $this->post("/api/v1/clients/{$client->id}/credential", [
            'pfx' => $this->pfx(),
            'password' => 'certificate-password',
        ], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Falha controlada com Bearer [redacted]',
                'code' => 'client_credential_activation_failed',
            ]);

        $this->assertDatabaseCount('client_credentials', 0);
    }

    public function test_permission_and_tenant_scope_fail_closed(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        Sanctum::actingAs($viewer);

        $this->getJson("/api/v1/clients/{$client->id}/credential")->assertForbidden();
        $this->postJson("/api/v1/clients/{$client->id}/credential")->assertForbidden();
        $this->getJson("/api/v1/clients/{$otherClient->id}/credential")->assertNotFound();
    }

    private function pfx(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('client.pfx', 'fake-pfx-binary');
    }
}

final class SuccessfulClientPfxReader implements PfxReaderInterface
{
    public function read(string $pfxBinary, string $password): array
    {
        return [
            'pfx' => $pfxBinary,
            'password' => $password,
            'subject_name' => 'Cliente Teste',
            'cnpj' => '11222333000181',
            'fingerprint_sha256' => str_repeat('a', 64),
            'valid_from' => CarbonImmutable::now()->subDay(),
            'valid_to' => CarbonImmutable::now()->addYear(),
        ];
    }
}

final class FailingClientPfxReader implements PfxReaderInterface
{
    public function read(string $pfxBinary, string $password): array
    {
        throw new RuntimeException('Falha controlada com Bearer secret-token');
    }
}

final class ClientCredentialMemoryStore implements SecureObjectStore
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
        return $this->objects[$objectId] ?? throw new RuntimeException('Objeto não encontrado.');
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return isset($this->objects[$objectId]);
    }
}
