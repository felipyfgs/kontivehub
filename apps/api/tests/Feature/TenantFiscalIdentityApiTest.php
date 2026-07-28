<?php

namespace Tests\Feature;

use App\Enums\TenantRole;
use App\Models\AuditLog;
use App\Models\Tenant;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class TenantFiscalIdentityApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_only_current_tenant_identity(): void
    {
        [$viewer, $tenant] = $this->actor('viewer');
        $otherTenant = Tenant::factory()->create();
        $identity = TenantFiscalIdentity::factory()->forTenant($tenant)->create();
        TenantFiscalIdentity::factory()
            ->forTenant($otherTenant)
            ->withCnpj('99888777000166')
            ->create();
        $this->authenticate($viewer);

        $this->getJson('/api/v1/tenant/fiscal-identity')
            ->assertOk()
            ->assertJsonPath('data.identity.id', $identity->id)
            ->assertJsonPath('data.identity.cnpj', $identity->cnpj)
            ->assertJsonMissingPath('data.identity.tenant_id')
            ->assertJsonMissing(['99888777000166']);

        $this->getJson(
            "/api/v1/tenant/fiscal-identity?tenant_id={$otherTenant->id}",
        )->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');
    }

    public function test_admin_upserts_identity_without_sensitive_audit_context(): void
    {
        [$admin, $tenant] = $this->admin();
        $this->authenticate($admin);
        $cnpj = '11222333000181';

        $this->postJson('/api/v1/tenant/fiscal-identity', [
            'cnpj' => 'inválido',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'tenant_fiscal_identity_invalid');

        $this->postJson('/api/v1/tenant/fiscal-identity', [
            'tenant_id' => Tenant::factory()->create()->id,
            'cnpj' => $cnpj,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('tenant_id');

        $this->postJson('/api/v1/tenant/fiscal-identity', [
            'cnpj' => $cnpj,
            'legal_name' => 'Kontive Fiscal',
        ])->assertCreated()
            ->assertJsonPath('data.cnpj', $cnpj)
            ->assertJsonPath('data.legal_name', 'Kontive Fiscal')
            ->assertJsonMissingPath('data.tenant_id');

        $this->assertDatabaseHas('tenant_fiscal_identities', [
            'tenant_id' => $tenant->id,
            'cnpj' => $cnpj,
            'legal_name' => 'Kontive Fiscal',
        ]);

        $auditContext = AuditLog::query()
            ->where('action', 'tenant_fiscal_identity.upsert')
            ->pluck('context')
            ->map(fn ($context): string => json_encode(
                $context,
                JSON_THROW_ON_ERROR,
            ))
            ->implode(' ');
        $this->assertStringNotContainsString($cnpj, $auditContext);
    }

    /** @return array{User, Tenant} */
    private function actor(string $profile): array
    {
        $tenant = Tenant::factory()->create();
        $actor = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, $profile)
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
