<?php

namespace Tests\Feature\Database;

use App\Enums\CredentialStatus;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ClientCredentialIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_one_active_credential_per_client_is_allowed(): void
    {
        $client = Client::factory()->create();
        ClientCredential::query()->withoutGlobalScopes()->create(
            $this->credentialPayload($client, CredentialStatus::Active, 'active-first'),
        );

        $this->expectException(QueryException::class);

        ClientCredential::query()->withoutGlobalScopes()->create(
            $this->credentialPayload($client, CredentialStatus::Active, 'active-second'),
        );
    }

    public function test_repeated_historical_fingerprint_is_allowed(): void
    {
        $client = Client::factory()->create();
        $payload = $this->credentialPayload(
            $client,
            CredentialStatus::Superseded,
            'historical-repeat',
        );

        ClientCredential::query()->withoutGlobalScopes()->create($payload);
        ClientCredential::query()->withoutGlobalScopes()->create($payload);

        self::assertSame(
            2,
            ClientCredential::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $client->tenant_id)
                ->where('client_id', $client->id)
                ->where('fingerprint_sha256', $payload['fingerprint_sha256'])
                ->count(),
        );
    }

    public function test_credential_cannot_reference_client_from_another_tenant(): void
    {
        $tenant = Tenant::factory()->create();
        $foreignClient = Client::factory()->create();

        $this->expectException(QueryException::class);

        ClientCredential::query()->withoutGlobalScopes()->create(array_merge(
            $this->credentialPayload(
                $foreignClient,
                CredentialStatus::Active,
                'cross-tenant',
            ),
            ['tenant_id' => $tenant->id],
        ));
    }

    public function test_deleting_client_cascades_its_credential_history(): void
    {
        $client = Client::factory()->create();
        $credential = ClientCredential::query()->withoutGlobalScopes()->create(
            $this->credentialPayload(
                $client,
                CredentialStatus::Superseded,
                'historical-cascade',
            ),
        );

        $client->delete();

        self::assertFalse(
            ClientCredential::query()->withoutGlobalScopes()->whereKey($credential->id)->exists(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialPayload(
        Client $client,
        CredentialStatus $status,
        string $fingerprintSeed,
    ): array {
        return [
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
            'status' => $status,
            'subject_name' => 'Certificado de teste',
            'holder_cnpj' => $client->root_cnpj.'000100',
            'fingerprint_sha256' => hash('sha256', $fingerprintSeed),
            'valid_from' => now()->subYear(),
            'valid_to' => now()->addYear(),
            'vault_object_id' => (string) Str::ulid(),
            'activated_at' => now()->subYear(),
            'superseded_at' => $status === CredentialStatus::Superseded
                ? now()->subMonth()
                : null,
        ];
    }
}
