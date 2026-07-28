<?php

namespace Tests\Feature;

use App\Enums\TenantAutXmlEnrollmentStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\Tenant;
use App\Models\TenantAutXmlEnrollment;
use App\Models\TenantDistributionCursor;
use App\Models\TenantDistributionRun;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantAutXmlApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('sefaz.autxml.quiet_hours_after_empty', 1);
    }

    public function test_viewer_reads_paginated_current_tenant_overview_without_scope_injection(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        $identity = TenantFiscalIdentity::factory()->forTenant($tenant)->create();
        $otherIdentity = TenantFiscalIdentity::factory()
            ->forTenant($otherTenant)
            ->withCnpj('99888777000166')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $establishment = Establishment::factory()->forClient($client)->create();
        $otherEstablishment = Establishment::factory()
            ->forClient($otherClient, '99888777000166')
            ->create();
        $enrollment = $this->enrollment(
            $tenant,
            $identity,
            $establishment,
        );
        $this->enrollment(
            $otherTenant,
            $otherIdentity,
            $otherEstablishment,
        );
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/autxml?per_page=1')
            ->assertOk()
            ->assertJsonPath('data.identity.id', $identity->id)
            ->assertJsonPath('data.enrollments.0.id', $enrollment->id)
            ->assertJsonPath(
                'data.enrollments.0.establishment_id',
                $establishment->id,
            )
            ->assertJsonPath('data.enrollments.0.status', 'PENDING')
            ->assertJsonPath('data.stream.stream_reason', 'CURSOR_MISSING')
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 1)
            ->assertJsonMissingPath('data.identity.tenant_id')
            ->assertJsonMissing([$otherEstablishment->id]);

        $this->getJson("/api/v1/tenant/autxml?tenant_id={$otherTenant->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->getJson('/api/v1/tenant/autxml?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('per_page');
    }

    public function test_enrollment_validates_permission_activity_and_tenant_boundary(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        TenantFiscalIdentity::factory()->forTenant($tenant)->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $active = Establishment::factory()->forClient($client)->create();
        $inactive = Establishment::factory()
            ->forClient($client, '11365521000169')
            ->branch()
            ->create(['is_active' => false]);
        $other = Establishment::factory()
            ->forClient($otherClient, '99888777000166')
            ->create();
        $this->authenticate($admin);

        $this->postJson('/api/v1/tenant/autxml/enrollments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('establishment_id');

        $this->postJson('/api/v1/tenant/autxml/enrollments', [
            'establishment_id' => $inactive->id,
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_autxml_establishment_inactive');

        $this->postJson('/api/v1/tenant/autxml/enrollments', [
            'establishment_id' => $other->id,
        ])->assertNotFound()
            ->assertJsonPath('code', 'tenant_autxml_establishment_not_found');

        $enrollmentId = (int) $this->postJson(
            '/api/v1/tenant/autxml/enrollments',
            ['establishment_id' => $active->id],
        )->assertCreated()
            ->assertJsonPath('data.status', 'PENDING')
            ->json('data.id');

        $this->postJson(
            "/api/v1/tenant/autxml/enrollments/{$enrollmentId}/inactivate",
        )->assertOk()
            ->assertJsonPath('data.status', 'INACTIVE');

        $this->postJson('/api/v1/tenant/autxml/enrollments', [
            'establishment_id' => $active->id,
        ])->assertCreated()
            ->assertJsonPath('data.id', $enrollmentId)
            ->assertJsonPath('data.status', 'PENDING');

        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $this->authenticate($viewer);
        $this->postJson('/api/v1/tenant/autxml/enrollments', [
            'establishment_id' => $active->id,
        ])->assertForbidden();
    }

    public function test_confirmation_is_tenant_scoped_and_requires_ready_stream(): void
    {
        [$admin, $tenant] = $this->admin();
        $otherTenant = Tenant::factory()->create();
        $identity = TenantFiscalIdentity::factory()->forTenant($tenant)->create();
        $otherIdentity = TenantFiscalIdentity::factory()
            ->forTenant($otherTenant)
            ->withCnpj('99888777000166')
            ->create();
        $client = Client::factory()->forTenant($tenant)->create();
        $otherClient = Client::factory()->forTenant($otherTenant)->create();
        $establishment = Establishment::factory()->forClient($client)->create();
        $otherEstablishment = Establishment::factory()
            ->forClient($otherClient, '99888777000166')
            ->create();
        $enrollment = $this->enrollment(
            $tenant,
            $identity,
            $establishment,
        );
        $otherEnrollment = $this->enrollment(
            $otherTenant,
            $otherIdentity,
            $otherEstablishment,
        );
        $cursor = TenantDistributionCursor::factory()
            ->forIdentity($identity)
            ->create(['activated_at' => now()]);
        $this->authenticate($admin);

        $this->postJson(
            "/api/v1/tenant/autxml/enrollments/{$otherEnrollment->id}/confirm",
        )->assertNotFound();

        $this->postJson(
            "/api/v1/tenant/autxml/enrollments/{$enrollment->id}/confirm",
        )->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_autxml_stream_not_ready')
            ->assertJsonPath('stream.stream_reason', 'QUIET_PENDING');

        $cursor->forceFill(['activated_at' => now()->subHours(2)])->save();

        $this->postJson(
            "/api/v1/tenant/autxml/enrollments/{$enrollment->id}/confirm",
        )->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED');

        $this->assertDatabaseHas('tenant_autxml_enrollments', [
            'id' => $enrollment->id,
            'tenant_id' => $tenant->id,
            'status' => TenantAutXmlEnrollmentStatus::Confirmed->value,
            'confirmed_by' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'tenant_id' => $tenant->id,
            'action' => 'tenant_autxml.enrollment_confirm',
        ]);
    }

    public function test_cursor_status_exposes_only_current_tenant_runs(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        $identity = TenantFiscalIdentity::factory()->forTenant($tenant)->create();
        $otherIdentity = TenantFiscalIdentity::factory()
            ->forTenant($otherTenant)
            ->withCnpj('99888777000166')
            ->create();
        $cursor = TenantDistributionCursor::factory()
            ->forIdentity($identity)
            ->create(['activated_at' => now()->subHours(2)]);
        $otherCursor = TenantDistributionCursor::factory()
            ->forIdentity($otherIdentity)
            ->create();
        $run = $this->distributionRun($tenant, $cursor, 'OWN_RUN');
        $this->distributionRun($otherTenant, $otherCursor, 'OTHER_RUN');
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/autxml/cursor')
            ->assertOk()
            ->assertJsonCount(1, 'data.cursors')
            ->assertJsonPath('data.cursors.0.id', $cursor->id)
            ->assertJsonPath('data.cursors.0.backoff', false)
            ->assertJsonPath('data.stream.stream_ready', true)
            ->assertJsonCount(1, 'data.recent_runs')
            ->assertJsonPath('data.recent_runs.0.id', $run->id)
            ->assertJsonMissing(['OTHER_RUN']);
    }

    private function enrollment(
        Tenant $tenant,
        TenantFiscalIdentity $identity,
        Establishment $establishment,
    ): TenantAutXmlEnrollment {
        return TenantAutXmlEnrollment::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tenant_fiscal_identity_id' => $identity->id,
            'establishment_id' => $establishment->id,
            'status' => TenantAutXmlEnrollmentStatus::Pending,
        ]);
    }

    private function distributionRun(
        Tenant $tenant,
        TenantDistributionCursor $cursor,
        string $errorCode,
    ): TenantDistributionRun {
        return TenantDistributionRun::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'tenant_distribution_cursor_id' => $cursor->id,
            'status' => 'DONE',
            'trigger' => 'SCHEDULED',
            'from_nsu' => 0,
            'to_nsu' => 10,
            'error_code' => $errorCode,
        ]);
    }

    /** @return array{User, Tenant} */
    private function actor(string $permissionProfile): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, $permissionProfile)
            ->create();

        return [$actor, $tenant];
    }

    /** @return array{User, Tenant} */
    private function admin(): array
    {
        $tenant = Tenant::factory()->create();
        $admin = User::factory()
            ->forTenant($tenant, TenantRole::TenantAdmin)
            ->create();

        return [$admin, $tenant];
    }

    private function authenticate(User $actor): void
    {
        Sanctum::actingAs($actor);
        app(CurrentTenant::class)->clear();
        app()->forgetInstance(TenantAuthorization::class);
    }
}
