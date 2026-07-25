<?php

namespace Tests\Unit\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\Enums\AuthorCertificateMode;
use App\Enums\AuthorIdentityType;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalProfile;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\SerproAuthorizationStatus;
use App\Enums\SerproEligibilityCode;
use App\Enums\SerproEnvironment;
use App\Enums\TaxProxyPowerSource;
use App\Enums\TaxProxyPowerStatus;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproContract;
use App\Models\TaxProxyPower;
use App\Services\Fiscal\SimplesMei\CcmeiPostConsultService;
use App\Services\Fiscal\SimplesMei\CcmeiRegistrationStatusPostConsultService;
use App\Services\Fiscal\SimplesMei\DefisDeclarationProjector;
use App\Services\Fiscal\SimplesMei\DefisDeclarationReferenceStore;
use App\Services\Fiscal\SimplesMei\DefisLatestDeclarationPostConsultService;
use App\Services\Fiscal\SimplesMei\DefisSpecificDeclarationPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdConsDeclaracao13Codec;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdDocumentCodecs;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPostConsultService;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiDividaAtiva24Codec;
use App\Services\Fiscal\SimplesMei\Pgmei\PgmeiPostConsultService;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use App\Services\Fiscal\SimplesMei\RegimeResolutionCodec;
use App\Services\Fiscal\SimplesMei\RegimeResolutionPostConsultService;
use App\Services\Fiscal\SimplesMei\SimplesMeiAdapter;
use App\Services\Fiscal\SimplesMei\SimplesMeiCatalog;
use App\Services\Fiscal\SimplesMei\SimplesMeiResponseMapper;
use App\Services\Integra\ContributorCnpjResolver;
use App\Services\Integra\EnsureClientProcuracaoForConsult;
use App\Services\Integra\IntegraEligibilityService;
use App\Services\Integra\OfficeSerproAuthorizationService;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SimplesMeiAdapterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fiscal.profile' => FiscalProfile::Dev->value,
            'fiscal_monitoring.enabled' => true,
            'serpro.default_environment' => 'TRIAL',
            'serpro.kill_switch' => false,
        ]);
        Http::fake();
    }

    public function test_pgmei_consultar_returns_classified_block_when_authorization_draft(): void
    {
        [$office, $client, $run] = $this->seedTenant();
        SerproContract::query()->create([
            'environment' => SerproEnvironment::Trial->value,
            'status' => 'ACTIVE',
            'contractor_cnpj' => '04252011000110',
            'health_status' => 'OK',
        ]);
        OfficeSerproAuthorization::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'status' => SerproAuthorizationStatus::Draft,
            'author_identity_type' => AuthorIdentityType::Cnpj,
            'author_identity' => '48123272000105',
            'certificate_mode' => AuthorCertificateMode::ManagedA1,
        ]);

        $adapter = $this->makeAdapter('INTEGRA_MEI', 'PGMEI', 'CONSULTAR');
        $result = $adapter->execute(new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_MEI',
            serviceCode: 'PGMEI',
            operationCode: 'CONSULTAR',
            trigger: FiscalTrigger::Manual,
            progress: ['ano_calendario' => '2024'],
            context: ['anoCalendario' => '2024'],
        ));

        $this->assertSame(FiscalRunResult::Blocked, $result->result);
        $this->assertSame(SerproEligibilityCode::AuthorizationMissing->value, $result->errorCode);
        Http::assertNothingSent();
    }

    public function test_pgdasd_consultar_with_fixture_source_returns_domain_result(): void
    {
        [$office, $client, $run] = $this->seedTenant(
            systemCode: 'INTEGRA_SN',
            serviceCode: 'PGDASD',
            operationCode: 'CONSULTAR_DECLARACAO',
            operationKey: 'pgdasd.consultar_declaracao',
        );
        $this->seedUsableSerpro($office, $client, powerCode: 'PGDASD');

        $adapter = $this->makeAdapter('INTEGRA_SN', 'PGDASD', 'CONSULTAR_DECLARACAO');
        $result = $adapter->execute(new FiscalAdapterRequest(
            office: $office,
            client: $client,
            run: $run,
            systemCode: 'INTEGRA_SN',
            serviceCode: 'PGDASD',
            operationCode: 'CONSULTAR_DECLARACAO',
            trigger: FiscalTrigger::Manual,
            progress: ['period_key' => '2025'],
            context: ['period_key' => '2025', 'ano_calendario' => '2025'],
        ));

        $this->assertContains(
            $result->result,
            [FiscalRunResult::Success, FiscalRunResult::Partial, FiscalRunResult::Failed, FiscalRunResult::Blocked],
        );
        $this->assertNotSame(SerproEligibilityCode::AuthorizationMissing->value, $result->errorCode);
        Http::assertNothingSent();
    }

    private function makeAdapter(string $system, string $service, string $operation): SimplesMeiAdapter
    {
        $def = SimplesMeiCatalog::find($system, $service, $operation);
        $this->assertNotNull($def);

        return new SimplesMeiAdapter(
            definition: $def,
            eligibility: app(IntegraEligibilityService::class),
            operations: app(SerproOperationService::class),
            mapper: app(SimplesMeiResponseMapper::class),
            contracts: app(SerproContractService::class),
            authorizations: app(OfficeSerproAuthorizationService::class),
            regimeApplicability: app(RegimeApplicabilityService::class),
            contributors: app(ContributorCnpjResolver::class),
            pgdasdCodec13: app(PgdasdConsDeclaracao13Codec::class),
            pgdasdDocumentCodecs: app(PgdasdDocumentCodecs::class),
            pgdasdPostConsult: app(PgdasdPostConsultService::class),
            pgmeiCodec24: app(PgmeiDividaAtiva24Codec::class),
            pgmeiPostConsult: app(PgmeiPostConsultService::class),
            ccmeiPostConsult: app(CcmeiPostConsultService::class),
            ccmeiRegistrationStatusPost: app(CcmeiRegistrationStatusPostConsultService::class),
            regimeResolutionCodec: app(RegimeResolutionCodec::class),
            regimeResolutionPost: app(RegimeResolutionPostConsultService::class),
            defisProjector: app(DefisDeclarationProjector::class),
            defisLatestDeclarationPost: app(DefisLatestDeclarationPostConsultService::class),
            defisSpecificDeclarationPost: app(DefisSpecificDeclarationPostConsultService::class),
            defisReferences: app(DefisDeclarationReferenceStore::class),
            procuracaoEnsure: app(EnsureClientProcuracaoForConsult::class),
        );
    }

    /** @return array{Office, Client, FiscalMonitoringRun} */
    private function seedTenant(
        string $systemCode = 'INTEGRA_MEI',
        string $serviceCode = 'PGMEI',
        string $operationCode = 'CONSULTAR',
        string $operationKey = 'pgmei.dividaativa',
    ): array {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create([
            'root_cnpj' => '26461528',
        ]);
        Establishment::factory()->forClient($client)->create([
            'office_id' => $office->id,
            'cnpj' => '26461528000151',
            'is_active' => true,
            'is_matrix' => true,
        ]);
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => $systemCode,
            'service_code' => $serviceCode,
            'operation_code' => $operationCode,
            'operation_key' => $operationKey,
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'sm-adapter:'.fake()->uuid(),
            'status' => FiscalRunStatus::Queued,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Unknown,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        return [$office, $client, $run];
    }

    private function seedUsableSerpro(Office $office, Client $client, string $powerCode): void
    {
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
            'procurador_token_vault_object_id' => '01JTOKENADAPTER00000000000',
            'procurador_token_expires_at' => now()->addHours(12),
            'termo_valid_to' => now()->addYear(),
        ]);

        TaxProxyPower::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'office_serpro_authorization_id' => $auth->id,
            'author_identity' => $auth->author_identity,
            'contributor_cnpj' => '26461528000151',
            'system_code' => $powerCode,
            'power_code' => $powerCode,
            'source' => TaxProxyPowerSource::IntegraProcuracoes,
            'status' => TaxProxyPowerStatus::Active,
            'environment' => SerproEnvironment::Trial->value,
            'provenance' => 'API_VERIFIED',
            'accepted_at' => now(),
            'freshness_checked_at' => now(),
            'verified_at' => now(),
            'valid_to' => now()->addYear(),
        ]);
    }
}
