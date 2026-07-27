<?php

namespace Tests\Unit\Serpro;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Models\FiscalModuleControl;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Models\User;
use App\Services\Serpro\SerproInitialMailboxSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproInitialMailboxSyncDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_tenant_restriction_blocks_initial_sync(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.enabled' => true,
        ]);
        $tenant = Tenant::factory()->create();
        $authorization = $this->authorizationFor($tenant);
        $this->restrictMailbox($tenant);

        $before = FiscalMonitoringRun::query()->count();
        $result = app(SerproInitialMailboxSyncDispatcher::class)->dispatchIfAllowed(
            tenant: $tenant,
            authorization: $authorization,
            idempotencyKey: 'mailbox-restriction-test',
            actorUserId: null,
            correlationId: null,
        );

        $this->assertSame('action_required', $result['state']);
        $this->assertSame('TENANT_RESTRICTION', $result['code']);
        $this->assertStringContainsString('Pausa Caixa Postal de teste', (string) $result['message']);
        $this->assertNull($result['run']);
        $this->assertSame($before, FiscalMonitoringRun::query()->count());
    }

    public function test_cross_tenant_authorization_is_rejected_before_dispatch(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
        ]);
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $authorization = $this->authorizationFor($otherTenant);

        $before = FiscalMonitoringRun::query()->count();
        $result = app(SerproInitialMailboxSyncDispatcher::class)->dispatchIfAllowed(
            tenant: $tenant,
            authorization: $authorization,
            idempotencyKey: 'mailbox-cross-tenant-test',
            actorUserId: null,
            correlationId: null,
        );

        $this->assertSame('action_required', $result['state']);
        $this->assertSame('AUTHORIZATION_CROSS_TENANT', $result['code']);
        $this->assertNull($result['run']);
        $this->assertSame($before, FiscalMonitoringRun::query()->count());
    }

    private function authorizationFor(Tenant $tenant): TenantSerproAuthorization
    {
        return TenantSerproAuthorization::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Production,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '12345678000195',
            'author_name' => 'Autor de teste',
            'certificate_mode' => AuthorCertificateMode::ExternalSignature,
            'termo_vault_object_id' => 'vault-termo-test',
            'procurador_token_expires_at' => now()->addHour(),
        ]);
    }

    private function restrictMailbox(Tenant $tenant): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Tenant,
            'tenant_id' => $tenant->id,
            'restricted' => true,
            'reason' => 'Pausa Caixa Postal de teste',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);
    }
}
