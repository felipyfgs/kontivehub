<?php

namespace Tests\Feature;

use App\Enums\RegistrationStatus;
use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Database\Factories\EstablishmentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class ClientAuxiliaryApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_lists_contacts_with_stable_resource_and_tenant_isolation(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $client = Client::factory()->forTenant($tenant)->create();
        $contact = ClientContact::factory()->forClient($client)->create([
            'name' => 'Contato Principal',
            'role' => 'Financeiro',
            'email' => 'financeiro@example.test',
            'phone' => '11999999999',
            'is_whatsapp' => true,
            'is_primary' => true,
            'receives_alerts' => true,
            'notes' => 'Observação interna',
            'is_active' => true,
        ]);
        $this->authenticate($viewer);

        $this->getJson("/api/v1/clients/{$client->id}/contacts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $contact->id)
            ->assertJsonPath('data.0.client_id', $client->id)
            ->assertJsonPath('data.0.name', 'Contato Principal')
            ->assertJsonPath('data.0.role', 'Financeiro')
            ->assertJsonPath('data.0.email', 'financeiro@example.test')
            ->assertJsonPath('data.0.phone', '11999999999')
            ->assertJsonPath('data.0.is_whatsapp', true)
            ->assertJsonPath('data.0.is_primary', true)
            ->assertJsonPath('data.0.receives_alerts', true)
            ->assertJsonPath('data.0.notes', 'Observação interna')
            ->assertJsonPath('data.0.is_active', true)
            ->assertJsonStructure([
                'data' => [[
                    'id',
                    'client_id',
                    'name',
                    'role',
                    'email',
                    'phone',
                    'is_whatsapp',
                    'is_primary',
                    'receives_alerts',
                    'notes',
                    'is_active',
                    'created_at',
                    'updated_at',
                ]],
            ]);

        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $this->getJson("/api/v1/clients/{$otherClient->id}/contacts")->assertNotFound();
    }

    public function test_operator_creates_contact_and_rejects_invalid_channel_or_primary_state(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $this->authenticate($operator);

        $this->postJson("/api/v1/clients/{$client->id}/contacts", [
            'name' => 'Sem canal',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email');

        $this->postJson("/api/v1/clients/{$client->id}/contacts", [
            'name' => 'Principal inativo',
            'email' => 'inativo@example.test',
            'is_primary' => true,
            'is_active' => false,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['is_primary', 'is_active']);

        $created = $this->postJson("/api/v1/clients/{$client->id}/contacts", [
            'name' => 'Maria Silva',
            'role' => 'Contabilidade',
            'email' => 'maria@example.test',
            'is_primary' => true,
            'receives_alerts' => true,
        ])->assertCreated()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.name', 'Maria Silva')
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.is_active', true);

        $this->assertDatabaseHas('client_contacts', [
            'id' => $created->json('data.id'),
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'email' => 'maria@example.test',
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'client_contact.create',
            'subject_id' => $created->json('data.id'),
        ]);
    }

    public function test_promoting_second_contact_demotes_previous_primary(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $first = ClientContact::factory()->forClient($client)->primary()->create([
            'name' => 'Primeiro contato',
            'email' => 'primeiro@example.test',
        ]);
        $this->authenticate($operator);

        $secondId = (int) $this->postJson("/api/v1/clients/{$client->id}/contacts", [
            'name' => 'Segundo contato',
            'email' => 'segundo@example.test',
        ])->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/clients/{$client->id}/contacts/{$secondId}", [
            'is_primary' => true,
            'phone' => '11988887777',
        ])->assertOk()
            ->assertJsonPath('data.id', $secondId)
            ->assertJsonPath('data.is_primary', true)
            ->assertJsonPath('data.phone', '11988887777');

        $this->assertDatabaseHas('client_contacts', [
            'id' => $first->id,
            'is_primary' => false,
        ]);
        $this->assertDatabaseHas('client_contacts', [
            'id' => $secondId,
            'is_primary' => true,
        ]);
    }

    public function test_contact_mutations_enforce_parent_match_permissions_and_delete_contract(): void
    {
        [$operator, $tenant] = $this->actor();
        $firstClient = Client::factory()->forTenant($tenant)->create();
        $secondClient = Client::factory()->forTenant($tenant)->create();
        $contact = ClientContact::factory()->forClient($secondClient)->create([
            'email' => 'contato@example.test',
        ]);
        $this->authenticate($operator);

        $this->patchJson(
            "/api/v1/clients/{$firstClient->id}/contacts/{$contact->id}",
            ['name' => 'Não permitido'],
        )->assertNotFound();

        $this->deleteJson(
            "/api/v1/clients/{$secondClient->id}/contacts/{$contact->id}",
        )->assertNoContent();
        $this->assertDatabaseMissing('client_contacts', ['id' => $contact->id]);

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $viewerContact = ClientContact::factory()->forClient($firstClient)->create([
            'email' => 'viewer@example.test',
        ]);
        $this->authenticate($viewer);

        $this->postJson("/api/v1/clients/{$firstClient->id}/contacts", [
            'name' => 'Bloqueado',
            'email' => 'bloqueado@example.test',
        ])->assertForbidden();
        $this->patchJson(
            "/api/v1/clients/{$firstClient->id}/contacts/{$viewerContact->id}",
            ['name' => 'Bloqueado'],
        )->assertForbidden();
        $this->deleteJson(
            "/api/v1/clients/{$firstClient->id}/contacts/{$viewerContact->id}",
        )->assertForbidden();
    }

    public function test_additional_establishment_is_rejected_with_stable_error_contract(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $this->authenticate($operator);

        $this->postJson("/api/v1/clients/{$client->id}/establishments", [
            'cnpj' => EstablishmentFactory::cnpjWithRoot($client->root_cnpj, '0002'),
            'trade_name' => 'Filial',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'additional_establishment_not_supported')
            ->assertJsonPath(
                'message',
                'Cada cliente possui um único estabelecimento. Cadastre a filial como um novo cliente.',
            )
            ->assertJsonValidationErrors('cnpj');
    }

    public function test_operator_updates_establishment_with_resource_contract(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $establishment = Establishment::factory()->forClient($client)->matrix()->create();
        $this->authenticate($operator);

        $this->patchJson("/api/v1/establishments/{$establishment->id}", [
            'trade_name' => 'Nome atualizado',
            'public_email' => 'publico@example.test',
            'address' => [
                'street' => 'Rua das Flores',
                'number' => '42',
                'state' => 'SP',
            ],
        ])->assertOk()
            ->assertJsonPath('data.id', $establishment->id)
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath('data.trade_name', 'Nome atualizado')
            ->assertJsonPath('data.public_email', 'publico@example.test')
            ->assertJsonPath('data.address.street', 'Rua das Flores')
            ->assertJsonPath('data.address.number', '42')
            ->assertJsonPath('data.address.state', 'SP')
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'tenant_id',
                    'client_id',
                    'cnpj',
                    'trade_name',
                    'is_headquarters',
                    'is_active',
                    'registration_status',
                    'address',
                    'capture_enabled',
                    'capture_eligibility' => [
                        'eligible',
                        'reasons',
                        'reasons_codes',
                        'channels',
                    ],
                    'created_at',
                    'updated_at',
                ],
            ]);

        $this->assertDatabaseHas('establishments', [
            'id' => $establishment->id,
            'tenant_id' => $tenant->id,
            'trade_name' => 'Nome atualizado',
            'address_street' => 'Rua das Flores',
        ]);
    }

    public function test_exceptional_capture_enable_requires_reason_without_persisting_free_text(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        $establishment = Establishment::factory()
            ->forClient($client)
            ->captureDisabled()
            ->create([
                'registration_status' => RegistrationStatus::Suspended,
            ]);
        $this->authenticate($operator);

        $this->patchJson("/api/v1/establishments/{$establishment->id}", [
            'capture_enabled' => true,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'capture_enable_reason_required')
            ->assertJsonValidationErrors('capture_enable_reason');

        $reason = 'Exceção interna para conferência manual 7F4A';
        $this->patchJson("/api/v1/establishments/{$establishment->id}", [
            'capture_enabled' => true,
            'capture_enable_reason' => $reason,
        ])->assertOk()
            ->assertJsonPath('data.capture_enabled', true);

        $audit = AuditLog::query()
            ->where('action', 'establishment.capture_enable')
            ->where('subject_id', $establishment->id)
            ->firstOrFail();
        $encodedContext = json_encode(
            $audit->context,
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );

        $this->assertTrue($audit->context['capture_enable_reason_present']);
        $this->assertSame(RegistrationStatus::Suspended->value, $audit->context['registration_status']);
        $this->assertStringNotContainsString($reason, $encodedContext);
    }

    public function test_establishment_update_enforces_single_headquarters_permissions_and_tenant_isolation(): void
    {
        [$operator, $tenant] = $this->actor();
        $client = Client::factory()->forTenant($tenant)->create();
        Establishment::factory()->forClient($client)->matrix()->create();
        $branch = Establishment::factory()
            ->forClient(
                $client,
                EstablishmentFactory::cnpjWithRoot($client->root_cnpj, '0002'),
            )
            ->branch()
            ->create();
        $this->authenticate($operator);

        $this->patchJson("/api/v1/establishments/{$branch->id}", [
            'is_headquarters' => true,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('is_headquarters');

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);
        $this->patchJson("/api/v1/establishments/{$branch->id}", [
            'trade_name' => 'Bloqueado',
        ])->assertForbidden();

        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $otherEstablishment = Establishment::factory()->forClient($otherClient)->create();
        $this->patchJson("/api/v1/establishments/{$otherEstablishment->id}", [
            'trade_name' => 'Outro tenant',
        ])->assertNotFound();
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

    private function authenticate(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
