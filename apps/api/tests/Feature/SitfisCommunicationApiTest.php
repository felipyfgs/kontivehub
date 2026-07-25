<?php

namespace Tests\Feature;

use App\Enums\OfficeRole;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\ClientContact;
use App\Models\Office;
use App\Models\User;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SitfisCommunicationApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fiscal_monitoring.enabled' => true,
            'fiscal_monitoring.communication.provider_enabled' => false,
        ]);
    }

    public function test_preference_patch_and_preview_for_sitfis_module(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->patchJson("/api/v1/fiscal/sitfis/clients/{$client->id}/communication-preference", [
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'automatic_requested' => true,
            'lock_version' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.automatic_requested', true)
            ->assertJsonPath('data.email_enabled', true);

        $this->assertDatabaseHas('client_communication_preferences', [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => SitfisCommunicationService::MODULE,
            'submodule_key' => SitfisCommunicationService::SUBMODULE,
            'automatic_requested' => true,
        ]);

        $this->getJson("/api/v1/fiscal/sitfis/clients/{$client->id}/communication-preview")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson("/api/v1/fiscal/sitfis/clients/{$client->id}/communications")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_send_fail_closed_when_provider_disabled(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => SitfisCommunicationService::MODULE,
            'submodule_key' => SitfisCommunicationService::SUBMODULE,
            'automatic_requested' => false,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        // Sem documento local: espera 422 (guard) ou Ok com provider_enabled false se houver artefato.
        // Para SITFIS o guard de documento pode diferir; assert fail-closed do provider quando send aceitar.
        $response = $this->postJson("/api/v1/fiscal/sitfis/clients/{$client->id}/communication-send");

        if ($response->status() === 200) {
            $response->assertJsonPath('data.provider_enabled', false);
        } else {
            $response->assertStatus(422);
        }
    }

    /**
     * @return array{0: Office, 1: User, 2: Client}
     */
    private function seedReadyClient(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        ClientContact::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'email' => 'sitfis-ops@example.com',
            'is_active' => true,
            'receives_alerts' => true,
        ]);

        return [$office, $user, $client];
    }
}
