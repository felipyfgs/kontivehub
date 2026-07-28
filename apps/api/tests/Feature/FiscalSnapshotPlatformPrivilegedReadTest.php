<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalPendingStatus;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\FiscalFinding;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\TenantPermissionProfile;
use App\Models\User;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalSnapshotPlatformPrivilegedReadTest extends TestCase
{
    use RefreshDatabase;

    public function test_platform_privileged_admin_without_dual_membership_can_list_snapshots(): void
    {
        config(['features.platform_privileged_context.enabled' => true]);

        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $actor = User::factory()->asPlatformAdmin($tenant->id)->create();

        Sanctum::actingAs($actor);
        $current = app(CurrentTenant::class);
        $current->clear();
        $current->bindPlatformPrivileged($actor, $tenant);

        $this->assertTrue($current->isPlatformPrivileged());
        $this->assertNull($current->realMembership());

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }

    public function test_tenant_user_with_empty_permission_profile_is_forbidden(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $actor = User::factory()->create();
        $profile = TenantPermissionProfile::query()->create([
            'tenant_id' => $tenant->id,
            'key' => 'empty',
            'name' => 'Sem permissões',
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
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertForbidden()
            ->assertJsonPath('message', 'Sem permissão para monitoramento fiscal.');
    }

    public function test_tenant_viewer_with_membership_can_list_snapshots(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();

        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?client_id='.$client->id.'&per_page=20&current_only=true')
            ->assertOk();
    }

    public function test_read_filters_are_validated_and_client_tenant_scope_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();

        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();

        $this->getJson('/api/v1/fiscal/snapshots?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/fiscal/findings?active_only=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['active_only']);
        $this->getJson('/api/v1/fiscal/pending-items?status=INVALID')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
        $this->getJson('/api/v1/fiscal/snapshots?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
    }

    public function test_resources_preserve_flat_pages_and_tenant_isolation(): void
    {
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        [$snapshot, $finding, $pending] = $this->fiscalGraph(
            $tenant,
            $client,
            'OWN',
        );
        [$foreignSnapshot, $foreignFinding, $foreignPending] = $this->fiscalGraph(
            $otherTenant,
            $otherClient,
            'FOREIGN',
        );
        Sanctum::actingAs($viewer);
        app(CurrentTenant::class)->clear();

        $this->getJson(
            '/api/v1/fiscal/snapshots?client_id='.$client->id
            .'&per_page=1&current_only=true',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $snapshot->toPublicArray())
            ->assertJsonMissing(['id' => $foreignSnapshot->id])
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/v1/fiscal/snapshots/'.$snapshot->id)
            ->assertOk()
            ->assertExactJson(['data' => $snapshot->toPublicArray()]);

        $this->getJson(
            '/api/v1/fiscal/findings?client_id='.$client->id
            .'&per_page=1&active_only=true',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $finding->toPublicArray())
            ->assertJsonMissing(['id' => $foreignFinding->id])
            ->assertJsonMissingPath('meta');

        $this->getJson(
            '/api/v1/fiscal/pending-items?client_id='.$client->id
            .'&per_page=1&status=OPEN',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $pending->toPublicArray())
            ->assertJsonMissing(['id' => $foreignPending->id])
            ->assertJsonMissingPath('meta');
    }

    /**
     * @return array{FiscalSnapshot, FiscalFinding, FiscalPendingItem}
     */
    private function fiscalGraph(
        Tenant $tenant,
        Client $client,
        string $seed,
    ): array {
        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'system_code' => 'TEST',
                'service_code' => 'READ',
                'operation_code' => 'MONITOR',
                'source_provenance' => FiscalSourceProvenance::SerproReal,
                'verification_state' => FiscalVerificationState::Verified,
                'trigger' => FiscalTrigger::Manual,
                'idempotency_key' => 'snapshot-resource-'.$seed,
                'status' => FiscalRunStatus::Completed,
                'result' => FiscalRunResult::Success,
                'situation' => FiscalSituation::Pending,
                'coverage' => FiscalCoverage::Full,
            ]);
        $snapshot = FiscalSnapshot::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'run_id' => $run->id,
                'client_id' => $client->id,
                'system_code' => 'TEST',
                'service_code' => 'READ',
                'operation_code' => 'MONITOR',
                'source_provenance' => FiscalSourceProvenance::SerproReal,
                'verification_state' => FiscalVerificationState::Verified,
                'situation' => FiscalSituation::Pending,
                'coverage' => FiscalCoverage::Full,
                'version' => 1,
                'is_current' => true,
                'normalized' => ['safe' => true],
                'observed_at' => now(),
            ]);
        $finding = FiscalFinding::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'snapshot_id' => $snapshot->id,
                'run_id' => $run->id,
                'client_id' => $client->id,
                'code' => 'FINDING_'.$seed,
                'severity' => FiscalFindingSeverity::High,
                'title' => 'Finding '.$seed,
                'detail' => 'Detalhe seguro',
                'situation' => FiscalSituation::Pending,
                'is_active' => true,
            ]);
        $pending = FiscalPendingItem::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'snapshot_id' => $snapshot->id,
                'run_id' => $run->id,
                'finding_id' => $finding->id,
                'code' => 'PENDING_'.$seed,
                'title' => 'Pendência '.$seed,
                'detail' => 'Detalhe seguro',
                'severity' => FiscalFindingSeverity::High,
                'status' => FiscalPendingStatus::Open,
                'situation' => FiscalSituation::Pending,
                'logical_key' => 'pending-'.$seed,
                'open_dedupe_key' => 'pending-'.$seed,
            ]);

        return [
            FiscalSnapshot::query()
                ->withoutGlobalScopes()
                ->findOrFail($snapshot->id),
            FiscalFinding::query()
                ->withoutGlobalScopes()
                ->findOrFail($finding->id),
            FiscalPendingItem::query()
                ->withoutGlobalScopes()
                ->findOrFail($pending->id),
        ];
    }
}
