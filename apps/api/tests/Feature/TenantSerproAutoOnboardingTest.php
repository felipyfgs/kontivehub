<?php

namespace Tests\Feature;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TenantCredentialPurpose;
use App\Enums\TenantRole;
use App\Enums\TenantSerproOnboardingStatus;
use App\Enums\TermRePresentationStrategy;
use App\Jobs\Serpro\RenewTenantProcuradorTokenJob;
use App\Models\Tenant;
use App\Models\TenantCredential;
use App\Models\TenantCredentialPurposeLink;
use App\Models\TenantInstitutionalProfile;
use App\Models\TenantSerproAuthorization;
use App\Models\TenantTechnicalConsent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Services\Integra\TenantSerproOnboardingService;
use App\Services\Serpro\SerproLifecycleMonitor;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TenantSerproAutoOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_signs_termo_via_dispatch_sync_not_manual_handle(): void
    {
        $source = file_get_contents(base_path('app/Services/Integra/TenantSerproOnboardingService.php'));
        self::assertIsString($source);
        self::assertStringContainsString('SignTermoWithManagedCertificateJob::dispatchSync(', $source);
        self::assertStringNotContainsString('$signJob->handle(', $source);
    }

    public function test_prerequisites_complete_with_certificate_sync_author_and_ready_in_dev(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant = Tenant::factory()->create(['name' => 'G A SILVA']);
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'G A SILVA ASSESSORIA',
        ]);
        TenantTechnicalConsent::factory()->forTenant($tenant)->create();
        $credential = TenantCredential::factory()->certificate()->forTenant($tenant)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);
        TenantCredentialPurposeLink::factory()->forTenant($tenant)->forCredential($credential)->create([
            'purpose' => TenantCredentialPurpose::SerproTermSigning,
            'status' => CredentialStatus::Active,
        ]);

        $service = app(TenantSerproOnboardingService::class);
        $result = $service->evaluateAndMaybeEnqueue($tenant, SerproEnvironment::Trial);

        $this->assertTrue($result['prerequisites']['complete']);
        $this->assertTrue($result['prerequisites']['certificate']);
        $this->assertSame(TenantSerproOnboardingStatus::Ready, $result['state']->status);

        $auth = TenantSerproAuthorization::query()
            ->where('tenant_id', $tenant->id)
            ->where('environment', SerproEnvironment::Trial)
            ->first();

        $this->assertNotNull($auth);
        $this->assertSame('11222333000181', $auth->author_identity);
        $this->assertSame(AuthorCertificateMode::ManagedCertificate, $auth->certificate_mode);
    }

    public function test_prerequisites_missing_certificate_stay_configuring(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create([
            'cnpj' => '11222333000181',
        ]);
        TenantTechnicalConsent::factory()->forTenant($tenant)->create();

        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::PendingTerm,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
        ]);

        $result = app(TenantSerproOnboardingService::class)
            ->evaluateAndMaybeEnqueue($tenant, SerproEnvironment::Trial);

        $this->assertFalse($result['prerequisites']['complete']);
        $this->assertSame('CERTIFICATE_REQUIRED', $result['prerequisites']['missing_code']);
        $this->assertSame(TenantSerproOnboardingStatus::Configuring, $result['state']->status);
    }

    public function test_lifecycle_dispatches_auto_renew_when_reuse_stored_term(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        config(['serpro.lifecycle.token_renewal_skew_seconds' => 300]);
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::ReuseStoredTerm->value]);
        // Trial: sem force o refresh seria no-op com token ainda válido no skew.
        config(['fiscal.profile' => FiscalProfile::Trial->value]);
        Queue::fake();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->addSeconds(60),
            // Sem termo: force=true deve tentar renovar e falhar (não no-op).
        ]);

        $result = app(SerproLifecycleMonitor::class)->scan();

        Queue::assertPushed(RenewTenantProcuradorTokenJob::class, function (RenewTenantProcuradorTokenJob $job) use ($tenant): bool {
            return $job->tenantId === (int) $tenant->id
                && strtoupper($job->environment) === SerproEnvironment::Trial->value;
        });

        $this->assertContains('AUTO_RENEW_SKEW', array_column($result['alerts'], 'severity'));

        $job = new RenewTenantProcuradorTokenJob(
            tenantId: (int) $tenant->id,
            environment: SerproEnvironment::Trial->value,
        );

        try {
            $job->handle(
                app(TenantSerproAuthorizationService::class),
                app(AuditLogger::class),
            );
            $this->fail('Renovação no skew com force deveria tentar autenticar e falhar sem Termo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Termo', $e->getMessage());
        }

        $auth = TenantSerproAuthorization::query()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
    }

    public function test_lifecycle_marks_action_required_when_strategy_forbids_reuse(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        config(['serpro.lifecycle.token_renewal_skew_seconds' => 300]);
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::PendingValidation->value]);
        Queue::fake();

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->subMinute(),
        ]);

        $result = app(SerproLifecycleMonitor::class)->scan();

        Queue::assertNotPushed(RenewTenantProcuradorTokenJob::class);
        $auth = TenantSerproAuthorization::query()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(SerproAuthorizationStatus::ActionRequired, $auth?->status);
        $this->assertContains('EXPIRED', array_column($result['alerts'], 'severity'));
    }

    public function test_renew_job_skips_when_strategy_is_not_reuse(): void
    {
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::RequireNewSignature->value]);

        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'termo_vault_object_id' => '01JTERMO00000000000000000',
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->subMinute(),
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        $job = new RenewTenantProcuradorTokenJob((int) $tenant->id, SerproEnvironment::Trial->value);
        $job->handle(
            app(TenantSerproAuthorizationService::class),
            app(AuditLogger::class),
        );

        $auth = TenantSerproAuthorization::query()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(SerproAuthorizationStatus::ActionRequired, $auth?->status);
        $this->assertSame(
            '01JTOKEN000000000000000000',
            $auth?->procurador_token_vault_object_id,
        );
    }

    public function test_trial_term_representation_default_is_reuse_stored_term(): void
    {
        // Relê config file default (sem override de env no processo de teste).
        $defaults = require config_path('serpro.php');
        $this->assertSame(
            TermRePresentationStrategy::ReuseStoredTerm->value,
            $defaults['term_representation']['TRIAL'],
        );
        $this->assertSame(
            TermRePresentationStrategy::PendingValidation->value,
            $defaults['term_representation']['PRODUCTION'],
        );
    }

    public function test_dev_ready_activates_fixture_procurador_token(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant = Tenant::factory()->create(['name' => 'CONTADOR DEV']);
        app(CurrentTenant::class)->bindSystem($tenant);
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'CONTADOR DEV LTDA',
        ]);
        TenantTechnicalConsent::factory()->forTenant($tenant)->create();
        $credential = TenantCredential::factory()->certificate()->forTenant($tenant)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);
        TenantCredentialPurposeLink::factory()->forCredential($credential)->serproTermSigning()->create();

        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        $result = app(TenantSerproOnboardingService::class)
            ->evaluateAndMaybeEnqueue($tenant, SerproEnvironment::Trial, force: true);

        $this->assertSame(TenantSerproOnboardingStatus::Ready, $result['state']->status);

        $auth = TenantSerproAuthorization::query()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
        $this->assertNotNull($auth?->procurador_token_vault_object_id);
        $this->assertTrue($auth?->procurador_token_expires_at?->isFuture());
        $this->assertNull($auth?->action_required_reason);
    }

    public function test_refresh_integration_endpoint_regenerates_token_without_reupload(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant = Tenant::factory()->create(['name' => 'CONTADOR REFRESH']);
        app(CurrentTenant::class)->bindSystem($tenant);
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        TenantInstitutionalProfile::factory()->forTenant($tenant)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'CONTADOR REFRESH LTDA',
        ]);
        TenantTechnicalConsent::factory()->forTenant($tenant)->create();
        $credential = TenantCredential::factory()->certificate()->forTenant($tenant)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);
        TenantCredentialPurposeLink::factory()->forCredential($credential)->serproTermSigning()->create();

        TenantSerproAuthorization::query()->create([
            'tenant_id' => $tenant->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedCertificate,
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/tenant/settings/refresh-integration')
            ->assertOk()
            ->assertJsonPath('data.status', SerproAuthorizationStatus::TokenActive->value)
            ->assertJsonPath('data.has_procurador_token', true)
            ->assertJsonPath('data.onboarding_evaluated', true);

        $auth = TenantSerproAuthorization::query()->where('tenant_id', $tenant->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
        $this->assertNotNull($auth?->procurador_token_vault_object_id);
        $this->assertNull($auth?->action_required_reason);
    }

    public function test_refresh_integration_requires_active_certificate(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $tenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantAdmin)->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/tenant/settings/refresh-integration')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Envie o certificado do escritório antes de atualizar a integração.',
            );
    }
}
