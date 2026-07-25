<?php

namespace Tests\Feature;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\CredentialStatus;
use App\Enums\FiscalProfile;
use App\Enums\OfficeCredentialPurpose;
use App\Enums\OfficeRole;
use App\Enums\OfficeSerproOnboardingStatus;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEnvironment;
use App\Enums\TermRePresentationStrategy;
use App\Jobs\Serpro\RenewOfficeProcuradorTokenJob;
use App\Models\Office;
use App\Models\OfficeCredential;
use App\Models\OfficeCredentialPurposeLink;
use App\Models\OfficeInstitutionalProfile;
use App\Models\OfficeSerproAuthorization;
use App\Models\OfficeTechnicalConsent;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Integra\OfficeSerproOnboardingService;
use App\Services\Serpro\SerproLifecycleMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OfficeSerproAutoOnboardingTest extends TestCase
{
    use RefreshDatabase;

    public function test_onboarding_signs_termo_via_dispatch_sync_not_manual_handle(): void
    {
        $source = file_get_contents(base_path('app/Services/Integra/OfficeSerproOnboardingService.php'));
        self::assertIsString($source);
        self::assertStringContainsString('SignTermoWithManagedA1Job::dispatchSync(', $source);
        self::assertStringNotContainsString('$signJob->handle(', $source);
    }

    public function test_prerequisites_complete_with_canonical_a1_sync_author_and_ready_in_dev(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create(['name' => 'G A SILVA']);
        OfficeInstitutionalProfile::factory()->forOffice($office)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'G A SILVA ASSESSORIA',
        ]);
        OfficeTechnicalConsent::factory()->forOffice($office)->create();
        $credential = OfficeCredential::factory()->canonical()->forOffice($office)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);
        OfficeCredentialPurposeLink::factory()->forOffice($office)->forCredential($credential)->create([
            'purpose' => OfficeCredentialPurpose::SerproTermSigning,
            'status' => CredentialStatus::Active,
        ]);

        $service = app(OfficeSerproOnboardingService::class);
        $result = $service->evaluateAndMaybeEnqueue($office, SerproEnvironment::Trial);

        $this->assertTrue($result['prerequisites']['complete']);
        $this->assertTrue($result['prerequisites']['a1']);
        $this->assertSame(OfficeSerproOnboardingStatus::Ready, $result['state']->status);

        $auth = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', SerproEnvironment::Trial)
            ->first();

        $this->assertNotNull($auth);
        $this->assertSame('11222333000181', $auth->author_identity);
        $this->assertSame(AuthorCertificateMode::ManagedA1, $auth->certificate_mode);
        $this->assertTrue((bool) $auth->managed_a1_consent);
        $this->assertNull($auth->author_pfx_vault_object_id);
    }

    public function test_prerequisites_missing_canonical_a1_stay_configuring(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create();
        OfficeInstitutionalProfile::factory()->forOffice($office)->create([
            'cnpj' => '11222333000181',
        ]);
        OfficeTechnicalConsent::factory()->forOffice($office)->create();

        // Autor legado sem A1 canônico nem author_pfx.
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::PendingTerm,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
        ]);

        $result = app(OfficeSerproOnboardingService::class)
            ->evaluateAndMaybeEnqueue($office, SerproEnvironment::Trial);

        $this->assertFalse($result['prerequisites']['complete']);
        $this->assertSame('A1_REQUIRED', $result['prerequisites']['missing_code']);
        $this->assertSame(OfficeSerproOnboardingStatus::Configuring, $result['state']->status);
    }

    public function test_lifecycle_dispatches_auto_renew_when_reuse_stored_term(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        config(['serpro.lifecycle.token_renewal_skew_seconds' => 300]);
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::ReuseStoredTerm->value]);
        // Trial: sem force o refresh seria no-op com token ainda válido no skew.
        config(['fiscal.profile' => FiscalProfile::Trial->value]);
        Queue::fake();

        $office = Office::factory()->create();
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->addSeconds(60),
            // Sem termo: force=true deve tentar renovar e falhar (não no-op).
        ]);

        $result = app(SerproLifecycleMonitor::class)->scan();

        Queue::assertPushed(RenewOfficeProcuradorTokenJob::class, function (RenewOfficeProcuradorTokenJob $job) use ($office): bool {
            return $job->officeId === (int) $office->id
                && strtoupper($job->environment) === SerproEnvironment::Trial->value;
        });

        $this->assertContains('AUTO_RENEW_SKEW', array_column($result['alerts'], 'severity'));

        $job = new RenewOfficeProcuradorTokenJob(
            officeId: (int) $office->id,
            environment: SerproEnvironment::Trial->value,
        );

        try {
            $job->handle(
                app(OfficeSerproAuthorizationService::class),
                app(AuditLogger::class),
            );
            $this->fail('Renovação no skew com force deveria tentar autenticar e falhar sem Termo.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Termo', $e->getMessage());
        }

        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
    }

    public function test_lifecycle_marks_action_required_when_strategy_forbids_reuse(): void
    {
        config(['serpro.lifecycle.alert_days' => [30, 7, 1]]);
        config(['serpro.lifecycle.token_renewal_skew_seconds' => 300]);
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::PendingValidation->value]);
        Queue::fake();

        $office = Office::factory()->create();
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->subMinute(),
        ]);

        $result = app(SerproLifecycleMonitor::class)->scan();

        Queue::assertNotPushed(RenewOfficeProcuradorTokenJob::class);
        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();
        $this->assertSame(SerproAuthorizationStatus::ActionRequired, $auth?->status);
        $this->assertContains('EXPIRED', array_column($result['alerts'], 'severity'));
    }

    public function test_renew_job_skips_when_strategy_is_not_reuse(): void
    {
        config(['serpro.term_representation.TRIAL' => TermRePresentationStrategy::RequireNewSignature->value]);

        $office = Office::factory()->create();
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'termo_vault_object_id' => '01JTERMO00000000000000000',
            'procurador_token_vault_object_id' => '01JTOKEN000000000000000000',
            'procurador_token_expires_at' => now()->subMinute(),
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        $job = new RenewOfficeProcuradorTokenJob((int) $office->id, SerproEnvironment::Trial->value);
        $job->handle(
            app(OfficeSerproAuthorizationService::class),
            app(AuditLogger::class),
        );

        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();
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

        $office = Office::factory()->create(['name' => 'CONTADOR DEV']);
        OfficeInstitutionalProfile::factory()->forOffice($office)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'CONTADOR DEV LTDA',
        ]);
        OfficeTechnicalConsent::factory()->forOffice($office)->create();
        OfficeCredential::factory()->canonical()->forOffice($office)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);

        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        $result = app(OfficeSerproOnboardingService::class)
            ->evaluateAndMaybeEnqueue($office, SerproEnvironment::Trial, force: true);

        $this->assertSame(OfficeSerproOnboardingStatus::Ready, $result['state']->status);

        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
        $this->assertNotNull($auth?->procurador_token_vault_object_id);
        $this->assertTrue($auth?->procurador_token_expires_at?->isFuture());
        $this->assertNull($auth?->action_required_reason);
    }

    public function test_refresh_integration_endpoint_regenerates_token_without_reupload(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create(['name' => 'CONTADOR REFRESH']);
        $actor = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        OfficeInstitutionalProfile::factory()->forOffice($office)->create([
            'cnpj' => '11222333000181',
            'legal_name' => 'CONTADOR REFRESH LTDA',
        ]);
        OfficeTechnicalConsent::factory()->forOffice($office)->create();
        OfficeCredential::factory()->canonical()->forOffice($office)->create([
            'holder_cnpj' => '11222333000181',
            'status' => CredentialStatus::Active,
        ]);

        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::ActionRequired,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '11222333000181',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'action_required_reason' => 'Token do procurador expirado; renovação exige ação explícita.',
        ]);

        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/office/settings/refresh-integration')
            ->assertOk()
            ->assertJsonPath('data.status', SerproAuthorizationStatus::TokenActive->value)
            ->assertJsonPath('data.has_procurador_token', true);

        $auth = OfficeSerproAuthorization::query()->where('office_id', $office->id)->first();
        $this->assertSame(SerproAuthorizationStatus::TokenActive, $auth?->status);
        $this->assertNotNull($auth?->procurador_token_vault_object_id);
        $this->assertNull($auth?->action_required_reason);
    }

    public function test_refresh_integration_requires_active_canonical_a1(): void
    {
        config()->set('fiscal.profile', FiscalProfile::Dev->value);

        $office = Office::factory()->create();
        $actor = User::factory()->forOffice($office, OfficeRole::Admin)->create();
        Sanctum::actingAs($actor);

        $this->postJson('/api/v1/office/settings/refresh-integration')
            ->assertUnprocessable()
            ->assertJsonPath(
                'message',
                'Envie o certificado A1 do escritório antes de atualizar a integração.',
            );
    }
}
