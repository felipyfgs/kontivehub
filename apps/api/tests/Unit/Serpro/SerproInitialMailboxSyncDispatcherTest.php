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
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\User;
use App\Services\Serpro\SerproInitialMailboxSyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproInitialMailboxSyncDispatcherTest extends TestCase
{
    use RefreshDatabase;

    public function test_office_restriction_cannot_be_reopened_by_legacy_flags(): void
    {
        config([
            'fiscal.profile' => 'dev',
            'fiscal.kill_switch' => false,
            'features.global_enabled' => true,
            'features.modules.mailbox.enabled' => true,
            'features.modules.mailbox.allow_all_offices' => true,
            'fiscal_monitoring.enabled' => true,
        ]);
        $office = Office::factory()->create();
        $authorization = $this->authorizationFor($office);
        $this->restrictMailbox($office);

        $before = FiscalMonitoringRun::query()->count();
        $result = app(SerproInitialMailboxSyncDispatcher::class)->dispatchIfAllowed(
            office: $office,
            authorization: $authorization,
            idempotencyKey: 'mailbox-restriction-test',
            actorUserId: null,
            correlationId: null,
        );

        $this->assertSame('action_required', $result['state']);
        $this->assertSame('OFFICE_RESTRICTION', $result['code']);
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
        $office = Office::factory()->create();
        $otherOffice = Office::factory()->create();
        $authorization = $this->authorizationFor($otherOffice);

        $before = FiscalMonitoringRun::query()->count();
        $result = app(SerproInitialMailboxSyncDispatcher::class)->dispatchIfAllowed(
            office: $office,
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

    private function authorizationFor(Office $office): OfficeSerproAuthorization
    {
        return OfficeSerproAuthorization::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Production,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '12345678000195',
            'author_name' => 'Autor de teste',
            'certificate_mode' => AuthorCertificateMode::ExternalSignature,
            'managed_a1_consent' => false,
            'termo_vault_object_id' => 'vault-termo-test',
            'procurador_token_expires_at' => now()->addHour(),
        ]);
    }

    private function restrictMailbox(Office $office): void
    {
        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Office,
            'office_id' => $office->id,
            'restricted' => true,
            'reason' => 'Pausa Caixa Postal de teste',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);
    }
}
