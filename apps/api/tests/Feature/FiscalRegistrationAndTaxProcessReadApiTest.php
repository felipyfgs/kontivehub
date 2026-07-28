<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalRegistrationLink;
use App\Models\FiscalTaxProcess;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalRegistrationAndTaxProcessReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_lists_registration_links_and_processes_with_compatible_envelopes(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $registration = $this->registration($tenant, $client, 'REG-OWN');
        $process = $this->process($tenant, $client, 'PROC-OWN');
        $this->registration($otherTenant, $otherClient, 'REG-OTHER');
        $this->process($otherTenant, $otherClient, 'PROC-OTHER');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/registrations?status=ACTIVE&q=REG-OWN&per_page=10')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $registration->id)
            ->assertJsonPath(
                'data.0.contributor_ref',
                substr(hash('sha256', '12345678000190'), 0, 12),
            )
            ->assertJsonMissingPath('data.0.contributor_cnpj')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.last_page', 1)
            ->assertJsonMissingPath('links');

        $this->getJson('/api/v1/fiscal/tax-processes?client_id='.$client->id.'&q=PROC-OWN')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $process->id)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('links');

        $this->getJson('/api/v1/fiscal/clients/'.$client->id.'/registrations')
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonCount(1, 'data.links')
            ->assertJsonPath('data.links.0.id', $registration->id);

        $this->getJson('/api/v1/fiscal/clients/'.$client->id.'/tax-processes')
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonCount(1, 'data.processes')
            ->assertJsonPath('data.processes.0.id', $process->id);

        $this->getJson('/api/v1/fiscal/tax-processes/'.$process->id)
            ->assertOk()
            ->assertJsonPath('data.id', $process->id);
    }

    public function test_read_boundaries_validate_filters_and_hide_cross_tenant_records(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $otherProcess = $this->process(
            $otherTenant,
            $otherClient,
            'PROC-OTHER',
        );
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/registrations?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/fiscal/tax-processes?q='.str_repeat('a', 121))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['q']);
        $this->getJson('/api/v1/fiscal/registrations?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);

        $this->getJson('/api/v1/fiscal/clients/'.$otherClient->id.'/registrations')
            ->assertNotFound();
        $this->getJson('/api/v1/fiscal/clients/'.$otherClient->id.'/tax-processes')
            ->assertNotFound();
        $this->getJson('/api/v1/fiscal/tax-processes/'.$otherProcess->id)
            ->assertNotFound();
    }

    public function test_member_without_monitoring_permission_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->create();
        $profile = TenantPermissionProfile::query()->create([
            'tenant_id' => $tenant->id,
            'key' => 'empty-fiscal-read',
            'name' => 'Sem permissões fiscais',
            'is_system' => false,
            'is_active' => true,
        ]);
        TenantMembership::factory()->create([
            'tenant_id' => $tenant->id,
            'user_id' => $actor->id,
            'role' => TenantRole::TenantUser,
            'permission_profile_id' => $profile->id,
            'is_active' => true,
        ]);
        Sanctum::actingAs($actor);

        $this->getJson('/api/v1/fiscal/registrations')
            ->assertForbidden()
            ->assertJsonPath(
                'message',
                'Sem permissão para monitoramento fiscal.',
            );
        $this->getJson('/api/v1/fiscal/tax-processes')
            ->assertForbidden();
    }

    private function registration(
        Tenant $tenant,
        Client $client,
        string $linkKey,
    ): FiscalRegistrationLink {
        return FiscalRegistrationLink::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'contributor_cnpj' => '12345678000190',
            'link_key' => $linkKey,
            'status' => 'ACTIVE',
            'source_provenance' => 'SERPRO',
            'summary_sanitized' => ['label' => $linkKey],
            'refreshed_at' => now(),
        ]);
    }

    private function process(
        Tenant $tenant,
        Client $client,
        string $processNumber,
    ): FiscalTaxProcess {
        return FiscalTaxProcess::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'contributor_cnpj' => '12345678000190',
            'process_number' => $processNumber,
            'status' => 'ACTIVE',
            'source_provenance' => 'SERPRO',
            'summary_sanitized' => ['label' => $processNumber],
            'refreshed_at' => now(),
        ]);
    }
}
