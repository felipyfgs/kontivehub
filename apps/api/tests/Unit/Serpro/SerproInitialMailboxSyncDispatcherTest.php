<?php

namespace Tests\Unit\Serpro;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproDataSegregationClass;
use App\Enums\SerproEnvironment;
use App\Enums\TenantSerproOnboardingStatus;
use App\Models\Client;
use App\Models\FiscalModuleControl;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;
use App\Models\TenantSerproOnboardingState;
use App\Models\User;
use App\Services\Serpro\SerproInitialMailboxSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('correlationIds')]
    public function test_initial_sync_propagates_correlation_with_deterministic_fallback(
        ?string $provided,
        string $idempotencyKey,
        string $expected,
    ): void {
        Queue::fake();
        config([
            'fiscal.profile' => 'production',
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.enabled' => true,
            'serpro.kill_switch' => false,
        ]);
        $tenant = Tenant::factory()->create([
            'serpro_segregation_class' => SerproDataSegregationClass::Production->value,
        ]);
        TenantSerproOnboardingState::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Production,
            'status' => TenantSerproOnboardingStatus::Ready,
            'idempotency_key' => 'mailbox-ready',
            'correlation_id' => 'mailbox-ready-correlation',
        ]);
        Client::factory()->forTenant($tenant)->create(['is_active' => true]);
        $authorization = $this->authorizationFor($tenant);

        $result = app(SerproInitialMailboxSyncDispatcher::class)->dispatchIfAllowed(
            tenant: $tenant,
            authorization: $authorization,
            idempotencyKey: $idempotencyKey,
            actorUserId: null,
            correlationId: $provided,
        );

        self::assertSame('queued', $result['state']);
        self::assertSame($expected, $result['run']?->correlation_id);
        self::assertLessThanOrEqual(64, strlen((string) $result['run']?->correlation_id));
    }

    /** @return iterable<string, array{string|null, string, string}> */
    public static function correlationIds(): iterable
    {
        yield 'propaga correlação recebida' => [
            'onboarding-correlation',
            'mailbox-correlation',
            'onboarding-correlation',
        ];
        yield 'fallback quando ausente' => [
            null,
            'mailbox-correlation',
            'serpro-prod-onboarding-mailbox-correlation',
        ];
        yield 'fallback quando vazia' => [
            ' ',
            'mailbox-correlation',
            'serpro-prod-onboarding-mailbox-correlation',
        ];

        $longIdempotencyKey = str_repeat('i', 96);
        yield 'fallback opaco para idempotência máxima' => [
            null,
            $longIdempotencyKey,
            'serpro-prod-onboarding-'.substr(hash('sha256', $longIdempotencyKey), 0, 40),
        ];

        $longCorrelation = str_repeat('c', 96);
        yield 'correlação recebida longa é normalizada' => [
            $longCorrelation,
            'mailbox-correlation',
            'external-'.substr(hash('sha256', $longCorrelation), 0, 55),
        ];
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
