<?php

namespace Database\Factories;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientProcuracaoSync>
 */
class ClientProcuracaoSyncFactory extends Factory
{
    protected $model = ClientProcuracaoSync::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'client_id' => function (array $attributes) {
                return Client::factory()->forTenant(
                    Tenant::query()->findOrFail($attributes['tenant_id'])
                )->create()->id;
            },
            'environment' => SerproEnvironment::Trial,
            'status' => ClientProcuracaoSyncStatus::Unverified,
            'valid_from' => null,
            'valid_to' => null,
            'last_verified_at' => null,
            'evidence_ref' => null,
            'evidence_sha256' => null,
            'power_codes' => null,
            'last_check_result' => null,
            'last_sync_error_code' => null,
            'source' => 'official_sync',
            'metadata' => null,
        ];
    }

    public function forTenant(Tenant $tenant): static
    {
        return $this->state(fn () => ['tenant_id' => $tenant->id]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'tenant_id' => $client->tenant_id,
            'client_id' => $client->id,
        ]);
    }

    public function authorized(): static
    {
        return $this->state(fn () => [
            'status' => ClientProcuracaoSyncStatus::Authorized,
            'valid_from' => now()->subMonth(),
            'valid_to' => now()->addYear(),
            'last_verified_at' => now(),
            'evidence_ref' => 'vault:test-procuracao',
            'evidence_sha256' => hash('sha256', 'test-procuracao'),
            'last_check_result' => 'AUTHORIZED',
        ]);
    }

    public function missing(): static
    {
        return $this->state(fn () => [
            'status' => ClientProcuracaoSyncStatus::Missing,
            'last_verified_at' => now(),
            'last_check_result' => 'MISSING',
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => ClientProcuracaoSyncStatus::Expired,
            'valid_from' => now()->subYears(2),
            'valid_to' => now()->subDay(),
            'last_verified_at' => now(),
            'last_check_result' => 'EXPIRED',
        ]);
    }
}
