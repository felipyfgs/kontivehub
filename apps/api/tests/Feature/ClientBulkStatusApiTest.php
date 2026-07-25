<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ClientBulkStatusApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_can_inactivate_clients_from_current_office_atomically(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Operator);
        $clients = Client::factory()->count(2)->forOffice($office)->create();
        Sanctum::actingAs($user);

        $response = $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => $clients->modelKeys(),
            'is_active' => false,
            'inactive_reason' => 'Inativado em massa pela lista de clientes',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.updated', 2)
            ->assertJsonPath('data.is_active', false);

        foreach ($clients as $client) {
            $this->assertDatabaseHas('clients', [
                'id' => $client->id,
                'office_id' => $office->id,
                'is_active' => false,
                'inactive_reason' => 'Inativado em massa pela lista de clientes',
            ]);
        }
    }

    public function test_reactivation_clears_inactive_reason(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        $client = Client::factory()->forOffice($office)->create([
            'is_active' => false,
            'inactive_reason' => 'Motivo anterior',
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$client->id],
            'is_active' => true,
        ])->assertOk();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => true,
            'inactive_reason' => null,
        ]);
    }

    public function test_cross_office_id_rejects_the_entire_batch(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Admin);
        $otherOffice = Office::factory()->create();
        $ownClient = Client::factory()->forOffice($office)->create();
        $otherClient = Client::factory()->forOffice($otherOffice)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$ownClient->id, $otherClient->id],
            'is_active' => false,
            'inactive_reason' => 'Não deve ser aplicado',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('client_ids');

        $this->assertDatabaseHas('clients', [
            'id' => $ownClient->id,
            'is_active' => true,
            'inactive_reason' => null,
        ]);
    }

    public function test_viewer_cannot_update_clients_in_bulk(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Viewer);
        $client = Client::factory()->forOffice($office)->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$client->id],
            'is_active' => false,
            'inactive_reason' => 'Sem permissão',
        ])->assertForbidden();

        $this->assertDatabaseHas('clients', [
            'id' => $client->id,
            'is_active' => true,
        ]);
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }
}
