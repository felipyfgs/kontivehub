<?php

namespace Tests\Feature;

use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\FiscalProfile;
use App\Enums\FiscalRunStatus;
use App\Enums\MeiProvider;
use App\Enums\OfficeRole;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEligibilityCode;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproContract;
use App\Models\TaxProxyPower;
use App\Models\User;
use App\Services\Fiscal\ManualConsult\ManualConsultReadPolicy;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Services\Integra\IntegraEligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Jornada PGMEI MONITOR após autorização utilizável (TokenActive) com fakes — sem egress real.
 */
final class PgmeiConsultHappyPathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fiscal.profile' => FiscalProfile::Dev->value,
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.enabled' => true,
            'serpro.default_environment' => 'TRIAL',
            'serpro.kill_switch' => false,
            'mei_automation.live_egress_enabled' => false,
            'mei_automation.provider_policy.default' => MeiProvider::Serpro->value,
            'mei_automation.provider_policy.operations' => [
                'pgmei.dividaativa' => MeiProvider::Serpro->value,
            ],
        ]);
        Http::fake();
    }

    public function test_usable_authorization_does_not_block_with_authorization_missing_from_draft(): void
    {
        [$office, $user, $client] = $this->seedUsableTrialContext();
        Sanctum::actingAs($user);

        $eligibility = app(IntegraEligibilityService::class)->evaluate(
            office: $office,
            client: $client,
            solutionCode: 'PGMEI',
            serviceCode: 'DIVIDAATIVA24',
            operationCode: '1.0',
            environment: SerproEnvironment::Trial,
        );

        $codes = array_map(
            static fn (SerproEligibilityCode $code): string => $code->value,
            $eligibility->codes,
        );
        $this->assertNotContains(
            SerproEligibilityCode::AuthorizationMissing->value,
            $codes,
            'TokenActive não deve produzir AUTHORIZATION_MISSING (DRAFT/PendingTerm).',
        );

        $runs = app(PgmeiMonitoringQueryService::class)->enqueueManualConsult(
            $office,
            [$client->id],
            2025,
            true,
            $user->id,
        );
        $this->assertCount(1, $runs);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->sole();
        (new ExecuteFiscalMonitoringRunJob($run->id))->handle(
            app(FiscalMonitoringRunService::class),
            app(ManualConsultReadPolicy::class),
        );

        $run->refresh();
        $this->assertNotSame(
            SerproEligibilityCode::AuthorizationMissing->value,
            $run->error_code,
            'Run não deve terminar em AUTHORIZATION_MISSING por DRAFT.',
        );
        $this->assertNotSame(FiscalRunStatus::Queued, $run->status);
        Http::assertNothingSent();
    }

    /** @return array{Office, User, Client} */
    private function seedUsableTrialContext(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->forOffice($office)->create([
            'root_cnpj' => '26461528',
        ]);
        Establishment::factory()->forClient($client)->create([
            'office_id' => $office->id,
            'cnpj' => '26461528000151',
            'is_active' => true,
            'is_matrix' => true,
        ]);

        SerproContract::query()->create([
            'environment' => SerproEnvironment::Trial->value,
            'status' => 'ACTIVE',
            'contractor_cnpj' => '04252011000110',
            'health_status' => 'OK',
        ]);

        $auth = OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::TokenActive,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '48123272000105',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
            'managed_a1_consent' => true,
            'procurador_token_vault_object_id' => '01JTOKENHAPPY0000000000000',
            'procurador_token_expires_at' => now()->addHours(12),
            'termo_valid_to' => now()->addYear(),
        ]);

        TaxProxyPower::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'office_serpro_authorization_id' => $auth->id,
            'author_identity' => $auth->author_identity,
            'contributor_cnpj' => '26461528000151',
            'system_code' => 'PGMEI',
            'power_code' => 'PGMEI',
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'status' => TaxProxyPowerStatus::Active,
            'environment' => SerproEnvironment::Trial->value,
            'provenance' => 'API_VERIFIED',
            'accepted_at' => now(),
            'freshness_checked_at' => now(),
            'verified_at' => now(),
            'valid_to' => now()->addYear(),
        ]);

        return [$office, $user, $client];
    }
}
