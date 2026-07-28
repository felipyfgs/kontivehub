<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\TaxRegimeCode;
use App\Models\CcmeiIssuedCertificate;
use App\Models\Client;
use App\Models\ClientTaxRegimePeriod;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\Tenant;
use App\Services\Fiscal\SimplesMei\RegimeApplicabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Concerns\SeedsSimplesNacionalPortfolio;
use Tests\TestCase;

class SimplesMeiReadApiTest extends TestCase
{
    use RefreshDatabase;
    use SeedsSimplesNacionalPortfolio;

    public function test_viewer_reads_catalog_and_local_projections_without_egress(): void
    {
        Bus::fake();
        Http::fake();
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsTenantUser($seed['viewer']);

        $period = ClientTaxRegimePeriod::query()->create([
            'tenant_id' => $seed['tenant']->id,
            'client_id' => $seed['sn']->id,
            'regime_code' => TaxRegimeCode::SimplesNacional,
            'effective_from' => '2026-01-01',
            'effective_to' => '2026-12-31',
            'source_system' => 'INTEGRA_SN',
            'source_service' => 'REGIME_APURACAO',
            'observed_at' => now(),
            'metadata' => [
                'operation_key' => 'regimeapuracao.consultaranoscalendarios',
                'calendar_year' => 2026,
                'regime_apuracao' => 'COMPETENCIA',
                'regime_option_103' => [
                    'operation_key' => 'regimeapuracao.consultaropcaoregime',
                    'calendar_year' => 2026,
                    'regime_apuracao' => 'CAIXA',
                ],
            ],
        ]);
        $artifact = $this->makeResolutionArtifact(
            $seed['tenant'],
            $seed['sn'],
            2026,
        );

        $this->getJson('/api/v1/fiscal/simples-mei/catalog')
            ->assertOk()
            ->assertJsonPath('module', 'simples_mei')
            ->assertJsonPath('data.0.module', 'simples_mei')
            ->assertJsonStructure([
                'data',
                'module',
                'module_enabled',
                'mutating_enabled',
            ]);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/regimes',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $period->id)
            ->assertJsonPath(
                'current_tax_regime',
                TaxRegimeCode::SimplesNacional->value,
            );

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/regime-calendar',
        )
            ->assertOk()
            ->assertJsonPath('data.0.calendar_year', 2026)
            ->assertJsonPath('data.0.regime_apuracao', 'COMPETENCIA')
            ->assertJsonPath('provenance.source', 'LOCAL_PROJECTION')
            ->assertJsonPath('provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/regime-options',
        )
            ->assertOk()
            ->assertJsonPath('data.0.calendar_year', 2026)
            ->assertJsonPath('data.0.regime_apuracao', 'CAIXA')
            ->assertJsonPath('provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/regime-resolutions?year=2026',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.calendar_year', 2026)
            ->assertJsonPath(
                'data.0.document.href',
                '/api/v1/fiscal/evidence/'.$artifact->id.'/download',
            )
            ->assertJsonMissingPath('data.0.vault_object_id')
            ->assertJsonMissingPath('data.0.content_sha256')
            ->assertJsonPath('provenance.serpro_called', false);

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_filters_competences_and_preserves_flat_snapshot_pagination(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $this->actingAsTenantUser($seed['viewer']);
        $snCategory = $this->makeCategory('SIMPLES_NACIONAL', 'INTEGRA_SN');
        $meiCategory = $this->makeCategory('MEI', 'INTEGRA_MEI');

        $this->makeCompetence(
            $seed['tenant'],
            $seed['sn'],
            $snCategory,
            '2026-05',
        );
        $meiCompetence = $this->makeCompetence(
            $seed['tenant'],
            $seed['sn'],
            $meiCategory,
            '2026-06',
        );

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/competences?regime_family=mei',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $meiCompetence->id)
            ->assertJsonPath('data.0.period_key', '2026-06');

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/competences?regime_family=INVALID',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['regime_family']);

        $run = $this->makeRun(
            $seed['tenant'],
            $seed['sn'],
            'INTEGRA_MEI',
        );
        $this->makeSnapshot($run, 'INTEGRA_MEI', '2026-05', false);
        $newer = $this->makeSnapshot($run, 'INTEGRA_MEI', '2026-06');
        $snRun = $this->makeRun(
            $seed['tenant'],
            $seed['sn'],
            'INTEGRA_SN',
        );
        $this->makeSnapshot($snRun, 'INTEGRA_SN', '2026-04');

        $response = $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id
            .'/snapshots?system_code=integra_mei&per_page=1&page=1',
        )
            ->assertOk()
            ->assertJsonPath('current_page', 1)
            ->assertJsonPath('per_page', 1)
            ->assertJsonPath('total', 2)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.0.system_code', 'INTEGRA_MEI')
            ->assertJsonMissingPath('meta')
            ->assertJsonStructure(['links']);

        $this->assertStringContainsString(
            'page=2',
            (string) $response->json('next_page_url'),
        );

        $base = '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id.'/snapshots';
        $this->getJson($base.'?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson($base.'?system_code=INVALID')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['system_code']);
        $this->getJson($base.'?page=0')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page']);
    }

    public function test_rejects_tenant_input_and_isolates_cross_tenant_clients(): void
    {
        $seed = $this->seedSimplesNacionalPortfolio();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->for($otherTenant)->create([
            'tax_regime' => TaxRegimeCode::Mei,
        ]);
        $this->actingAsTenantUser($seed['viewer']);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/clients/'.$seed['sn']->id
            .'/regime-calendar?filters[tenant_id]='.$seed['tenant']->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        $suffixes = [
            'regimes',
            'competences',
            'snapshots',
            'regime-calendar',
            'regime-options',
            'regime-resolutions',
        ];

        foreach ($suffixes as $suffix) {
            $this->getJson(
                '/api/v1/fiscal/simples-mei/clients/'
                .$otherClient->id.'/'.$suffix,
            )
                ->assertNotFound()
                ->assertJsonPath('message', 'Cliente não encontrado.');
        }
    }

    public function test_pgdasd_and_pgmei_read_surfaces_remain_local_and_tenant_scoped(): void
    {
        Bus::fake();
        Http::fake();
        $seed = $this->seedSimplesNacionalPortfolio();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->for($otherTenant)->create([
            'tax_regime' => TaxRegimeCode::Mei,
        ]);
        $this->actingAsTenantUser($seed['viewer']);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgdasd/clients/'
            .$seed['sn']->id.'/history?year=2026',
        )
            ->assertOk()
            ->assertJsonPath('data.client.id', $seed['sn']->id)
            ->assertJsonPath('data.provenance.source', 'LOCAL_PROJECTION')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgmei/clients/'
            .$seed['mei']->id.'/history?year=2026',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['mei']->id)
            ->assertJsonPath('data.year', 2026)
            ->assertJsonPath('data.provenance.source', 'local_projection')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgdasd/clients/'
            .$seed['sn']->id.'/communication-preview',
        )
            ->assertOk()
            ->assertJsonPath('data.client.id', $seed['sn']->id)
            ->assertJsonPath('data.provider_enabled', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgdasd/clients/'
            .$seed['sn']->id.'/communications',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['sn']->id);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgmei/clients/'
            .$seed['mei']->id.'/communication-preview',
        )
            ->assertOk()
            ->assertJsonPath('data.client.id', $seed['mei']->id)
            ->assertJsonPath('data.provider_enabled', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgmei/clients/'
            .$seed['mei']->id.'/communications',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['mei']->id);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgdasd/clients/'
            .$seed['sn']->id.'/history?year=1999',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year']);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/pgmei/clients/'
            .$seed['mei']->id.'/history?filters[tenant_id]='
            .$seed['tenant']->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        foreach (['pgdasd', 'pgmei'] as $module) {
            $this->getJson(
                '/api/v1/fiscal/simples-mei/'.$module.'/clients/'
                .$otherClient->id.'/history',
            )
                ->assertNotFound()
                ->assertJsonPath('code', 'CLIENT_NOT_FOUND');
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_ccmei_read_surfaces_remain_local_and_tenant_scoped(): void
    {
        Bus::fake();
        Http::fake();
        $executor = $this->createMock(SerproOperationExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(SerproOperationExecutor::class, $executor);
        $objects = $this->createMock(SecureObjectStore::class);
        $objects->expects($this->once())
            ->method('get')
            ->willReturn('%PDF-1.4 ccmei');
        $this->app->instance(SecureObjectStore::class, $objects);

        $seed = $this->seedSimplesNacionalPortfolio();
        $otherTenant = Tenant::factory()->create();
        $otherClient = Client::factory()->for($otherTenant)->create([
            'tax_regime' => TaxRegimeCode::Mei,
        ]);
        $this->actingAsTenantUser($seed['viewer']);
        $base = '/api/v1/fiscal/simples-mei/ccmei/clients/'.$seed['mei']->id;
        $certificate = $this->makeCcmeiCertificate(
            $seed['tenant'],
            $seed['mei'],
            'ccmei-local',
        );
        $otherCertificate = $this->makeCcmeiCertificate(
            $otherTenant,
            $otherClient,
            'ccmei-other',
        );

        $this->getJson($base.'/history')
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['mei']->id)
            ->assertJsonPath('data.provenance.source', 'local_projection')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson($base.'/issued-certificates')
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['mei']->id)
            ->assertJsonCount(1, 'data.certificates')
            ->assertJsonPath('data.certificates.0.id', $certificate->id)
            ->assertJsonMissingPath(
                'data.certificates.0.certificate_vault_object_id',
            )
            ->assertJsonMissingPath('data.certificates.0.certificate_sha256')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/ccmei/registration-status/clients/'
            .$seed['mei']->id.'/history',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $seed['mei']->id)
            ->assertJsonPath('data.provenance.source', 'local_projection')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson($base.'/history?filters[tenant_id]='.$seed['tenant']->id)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        $this->getJson(
            '/api/v1/fiscal/simples-mei/ccmei/clients/'
            .$otherClient->id.'/history',
        )
            ->assertNotFound()
            ->assertJsonPath('code', 'CLIENT_NOT_FOUND');

        $this->get($base.'/issued-certificates/'.$certificate->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="ccmei-certificado-'.$certificate->id.'.pdf"',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'max-age=0, no-store, private')
            ->assertContent('%PDF-1.4 ccmei');

        $this->getJson(
            $base.'/issued-certificates/'.$otherCertificate->id.'/download',
        )
            ->assertNotFound()
            ->assertJsonPath('message', 'Certificado não encontrado.');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function makeCategory(string $code, string $system): FiscalCategory
    {
        return FiscalCategory::query()->create([
            'code' => $code,
            'name' => $code,
            'module_key' => 'simples_mei',
            'system_code' => $system,
            'service_code' => $code,
        ]);
    }

    private function makeCompetence(
        Tenant $tenant,
        Client $client,
        FiscalCategory $category,
        string $periodKey,
    ): FiscalCompetence {
        return FiscalCompetence::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'fiscal_category_id' => $category->id,
            'period_key' => $periodKey,
            'period_year' => (int) substr($periodKey, 0, 4),
            'period_month' => (int) substr($periodKey, 5, 2),
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
        ]);
    }

    private function makeRun(
        Tenant $tenant,
        Client $client,
        string $system,
    ): FiscalMonitoringRun {
        return FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => $system,
            'service_code' => $system === 'INTEGRA_MEI' ? 'PGMEI' : 'PGDASD',
            'operation_code' => 'MONITOR',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'simples-mei-read-'.Str::uuid(),
            'status' => 'COMPLETED',
            'result' => FiscalRunResult::Success,
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'attempt' => 1,
            'correlation_id' => (string) Str::uuid(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);
    }

    private function makeSnapshot(
        FiscalMonitoringRun $run,
        string $system,
        string $periodKey,
        bool $isCurrent = true,
    ): FiscalSnapshot {
        return FiscalSnapshot::query()->create([
            'tenant_id' => $run->tenant_id,
            'run_id' => $run->id,
            'client_id' => $run->client_id,
            'system_code' => $system,
            'service_code' => $run->service_code,
            'operation_code' => 'MONITOR',
            'situation' => FiscalSituation::Pending,
            'coverage' => FiscalCoverage::Full,
            'version' => 1,
            'is_current' => $isCurrent,
            'normalized' => ['period_key' => $periodKey],
            'observed_at' => now(),
            'created_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ]);
    }

    private function makeResolutionArtifact(
        Tenant $tenant,
        Client $client,
        int $year,
    ): FiscalEvidenceArtifact {
        $run = $this->makeRun($tenant, $client, 'INTEGRA_SN');
        $artifact = FiscalEvidenceArtifact::query()->create([
            'tenant_id' => $tenant->id,
            'run_id' => $run->id,
            'vault_object_id' => '01HZY000000000000000000001',
            'content_sha256' => hash('sha256', 'resolution-'.$year),
            'content_type' => 'text/plain; charset=UTF-8',
            'byte_size' => 42,
            'source' => 'SERPRO',
            'observed_at' => now(),
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
            'operation_key' => 'regimeapuracao.consultarresolucao',
        ]);

        app(RegimeApplicabilityService::class)->projectResolution(
            $tenant,
            $client,
            $year,
            $artifact->id,
            $run->id,
        );

        return $artifact;
    }

    private function makeCcmeiCertificate(
        Tenant $tenant,
        Client $client,
        string $seed,
    ): CcmeiIssuedCertificate {
        return CcmeiIssuedCertificate::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'contributor_cnpj' => '12345678000195',
            'certificate_vault_object_id' => '01HZY000000000000000000001',
            'certificate_sha256' => hash('sha256', $seed),
            'certificate_mime_type' => 'application/pdf',
            'certificate_byte_size' => 16,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'observed_at' => now(),
        ]);
    }
}
