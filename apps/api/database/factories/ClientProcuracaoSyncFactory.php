<?php

namespace Database\Factories;

use App\Enums\ClientProcuracaoSyncStatus;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Office;
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
            'office_id' => Office::factory(),
            'client_id' => function (array $attributes) {
                return Client::factory()->forOffice(
                    Office::query()->findOrFail($attributes['office_id'])
                )->create()->id;
            },
            'status' => ClientProcuracaoSyncStatus::Unverified,
            'valid_from' => null,
            'valid_to' => null,
            'last_verified_at' => null,
            'evidence_ref' => null,
            'evidence_sha256' => null,
            'powers_summary' => null,
            'last_check_result' => null,
            'last_sync_error_code' => null,
            'source' => 'official_sync',
        ];
    }

    public function forOffice(Office $office): static
    {
        return $this->state(fn () => ['office_id' => $office->id]);
    }

    public function forClient(Client $client): static
    {
        return $this->state(fn () => [
            'office_id' => $client->office_id,
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
