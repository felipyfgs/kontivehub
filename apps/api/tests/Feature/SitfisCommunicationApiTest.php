<?php

namespace Tests\Feature;

use App\Enums\TaxRegimeCode;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\ClientContact;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Fiscal\Dctfweb\MitCommunicationService;
use App\Services\Fiscal\Fgts\FgtsCommunicationService;
use App\Services\Fiscal\Sitfis\SitfisCommunicationService;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
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
        [$tenant, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

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
            'tenant_id' => $tenant->id,
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
        [$tenant, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        ClientCommunicationPreference::query()->create([
            'tenant_id' => $tenant->id,
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

    public function test_shared_module_reads_are_tenant_scoped_and_side_effect_free(): void
    {
        Bus::fake();
        Http::fake();
        [$tenant, $user, $client] = $this->seedReadyClient();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();

        $services = [
            'sitfis' => app(SitfisCommunicationService::class),
            'fgts' => app(FgtsCommunicationService::class),
            'mit' => app(MitCommunicationService::class),
        ];

        foreach ($services as $module => $service) {
            $this->getJson(
                "/api/v1/fiscal/{$module}/clients/{$client->id}"
                .'/communication-preview',
            )
                ->assertOk()
                ->assertExactJson([
                    'data' => $service->preview($tenant, $client),
                ]);
            $this->getJson(
                "/api/v1/fiscal/{$module}/clients/{$client->id}"
                .'/communications',
            )
                ->assertOk()
                ->assertExactJson([
                    'data' => $service->tracking($tenant, $client),
                ]);
        }

        $this->getJson(
            "/api/v1/fiscal/sitfis/clients/{$otherClient->id}"
            .'/communication-preview',
        )->assertNotFound();
        $this->json(
            'GET',
            "/api/v1/fiscal/mit/clients/{$client->id}"
            .'/communications',
            ['scope' => ['tenant_id' => $tenant->id]],
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');
        $this->getJson(
            "/api/v1/fiscal/unknown/clients/{$client->id}"
            .'/communication-preview',
        )->assertNotFound();

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /**
     * @return array{0: Tenant, 1: User, 2: Client}
     */
    private function seedReadyClient(): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $client = Client::factory()->for($tenant)->create([
            'is_active' => true,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        ClientContact::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'email' => 'sitfis-ops@example.com',
            'is_active' => true,
            'receives_alerts' => true,
        ]);

        return [$tenant, $user, $client];
    }
}
