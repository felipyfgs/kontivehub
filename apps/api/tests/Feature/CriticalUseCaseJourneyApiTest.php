<?php

namespace Tests\Feature;

use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalSituation;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalPendingItem;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkProcess;
use App\Models\WorkTask;
use App\Services\Authorization\SystemTenantPermissionProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class CriticalUseCaseJourneyApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
    }

    public function test_identity_and_tenant_switch_preserve_tenant_isolation(): void
    {
        $primary = Tenant::factory()->create(['name' => 'Tenant Primário']);
        $secondary = Tenant::factory()->create(['name' => 'Tenant Secundário']);
        $user = User::factory()->forTenant($primary, TenantRole::TenantUser)->create();
        $profiles = app(SystemTenantPermissionProfiles::class)->ensure($secondary);
        $secondary->users()->attach($user->id, [
            'role' => TenantRole::TenantUser->value,
            'permission_profile_id' => $profiles['operator']->id,
            'is_active' => true,
        ]);
        $user->forceFill(['selected_tenant_id' => $primary->id])->saveQuietly();

        $primaryClient = Client::factory()->forTenant($primary)->create(['legal_name' => 'Cliente Primário']);
        $secondaryClient = Client::factory()->forTenant($secondary)->create(['legal_name' => 'Cliente Secundário']);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/tenants/memberships')
            ->assertOk()
            ->assertJsonPath('data.current_tenant_id', $primary->id)
            ->assertJsonCount(2, 'data.memberships');

        $this->postJson('/api/v1/tenants/switch', ['tenant_id' => $secondary->id])
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $secondary->id);

        $ids = $this->getJson('/api/v1/clients?per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($secondaryClient->id, $ids);
        $this->assertNotContains($primaryClient->id, $ids);
        Http::assertNothingSent();
    }

    public function test_client_catalog_is_tenant_scoped_and_viewer_cannot_mutate(): void
    {
        [$viewer, $tenant] = $this->actor(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create();
        $own = Client::factory()->forTenant($tenant)->create(['legal_name' => 'Cliente Próprio']);
        $other = Client::factory()->forTenant($otherTenant)->create(['legal_name' => 'Cliente Externo']);
        Sanctum::actingAs($viewer);

        $ids = $this->getJson('/api/v1/clients?tenant_id='.$otherTenant->id.'&per_page=50')
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->patchJson('/api/v1/clients/bulk-status', [
            'client_ids' => [$own->id],
            'is_active' => false,
            'inactive_reason' => 'Teste de permissão viewer',
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_work_queue_is_tenant_scoped_and_viewer_cannot_create_process(): void
    {
        [$viewer, $tenant] = $this->actor(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $own = WorkProcess::factory()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $ownClient->id,
            'title' => 'Processo Próprio',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $tenant->id,
            'work_process_id' => $own->id,
            'title' => 'Tarefa Própria',
        ]);
        $other = WorkProcess::factory()->create([
            'tenant_id' => $otherTenant->id,
            'client_id' => $otherClient->id,
            'title' => 'Processo Externo',
        ]);
        WorkTask::factory()->create([
            'tenant_id' => $otherTenant->id,
            'work_process_id' => $other->id,
            'title' => 'Tarefa Externa',
        ]);
        Sanctum::actingAs($viewer);

        $ids = $this->getJson('/api/v1/work/processes?tenant_id='.$otherTenant->id)
            ->assertOk()
            ->json('data.*.id');
        $this->assertContains($own->id, $ids);
        $this->assertNotContains($other->id, $ids);

        $this->postJson('/api/v1/work/processes', [
            'client_id' => $ownClient->id,
            'title' => 'Não permitido',
            'competence' => now()->format('Y-m'),
            'tasks' => [['title' => 'Tarefa']],
        ])->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_fiscal_monitoring_is_tenant_scoped_and_does_not_egress(): void
    {
        [$viewer, $tenant] = $this->actor(TenantRole::TenantUser);
        $otherTenant = Tenant::factory()->create();
        $ownClient = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $this->pending($tenant, $ownClient, 'OWN', 'Pendência própria');
        $this->pending($otherTenant, $otherClient, 'OTHER', 'Pendência externa');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/monitoring/insights')
            ->assertOk()
            ->assertJsonPath('data.pending.total', 1)
            ->assertJsonPath('data.pending.items.0.title', 'Pendência própria');
        Http::assertNothingSent();
    }

    /** @return array{User, Tenant} */
    private function actor(TenantRole $role): array
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant, $role, 'viewer')->create();
        $user->forceFill(['selected_tenant_id' => $tenant->id])->saveQuietly();

        return [$user, $tenant];
    }

    private function pending(Tenant $tenant, Client $client, string $code, string $title): void
    {
        FiscalPendingItem::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'code' => $code,
            'title' => $title,
            'severity' => FiscalFindingSeverity::Medium,
            'status' => FiscalPendingStatus::Open,
            'situation' => FiscalSituation::Pending,
            'logical_key' => strtolower($code).'-logical',
            'open_dedupe_key' => strtolower($code).'-open',
        ]);
    }
}
