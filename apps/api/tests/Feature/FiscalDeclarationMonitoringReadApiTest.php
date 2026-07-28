<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Contracts\SerproOperationExecutor;
use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\DefisDeclarationProjection;
use App\Models\DefisDeclarationReference;
use App\Models\DefisLatestDeclarationArtifact;
use App\Models\DefisSpecificDeclarationArtifact;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class FiscalDeclarationMonitoringReadApiTest extends TestCase
{
    use RefreshDatabase;

    private FiscalDeclarationReadObjectStore $objects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new FiscalDeclarationReadObjectStore;
        $this->app->instance(SecureObjectStore::class, $this->objects);
    }

    public function test_viewer_reads_local_dctfweb_and_defis_surfaces_without_side_effects(): void
    {
        Bus::fake();
        Http::fake();
        $executor = $this->createMock(SerproOperationExecutor::class);
        $executor->expects($this->never())->method('execute');
        $this->app->instance(SerproOperationExecutor::class, $executor);

        $tenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $declaration = $this->makeDctfwebDeclaration(
            $tenant,
            $client,
            '2026-05',
        );
        $run = $this->makeRun($tenant, $client, 'declaration-read');
        $reference = $this->makeDefisReference($tenant, $client);
        $projection = $this->makeDefisProjection(
            $tenant,
            $client,
            $reference,
        );
        $latestArtifact = $this->makeLatestDefisArtifact(
            $tenant,
            $client,
            $run,
            '%PDF latest',
        );
        $specificArtifact = $this->makeSpecificDefisArtifact(
            $tenant,
            $client,
            $run,
            $reference,
            '%PDF specific',
        );
        $this->makeDctfwebEvidence(
            $tenant,
            $client,
            $run,
            $declaration,
            '%PDF dctfweb',
        );
        $this->actingAsTenantUser($viewer);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id
            .'/history?year=2026',
        )
            ->assertOk()
            ->assertJsonPath('data.client.id', $client->id)
            ->assertJsonCount(1, 'data.periods')
            ->assertJsonPath('data.periods.0.period_key', '2026-05')
            ->assertJsonPath('data.provenance.source', 'LOCAL_PROJECTION')
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id
            .'/communication-preview',
        )
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id.'/communications',
        )
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/clients/'
            .$client->id.'/history',
        )
            ->assertOk()
            ->assertJsonPath('data.client_id', $client->id)
            ->assertJsonPath(
                'data.declarations.0.calendar_year',
                $projection->calendar_year,
            )
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/latest-declaration/clients/'
            .$client->id.'/history?year=2026',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.id', $latestArtifact->id)
            ->assertJsonMissingPath(
                'data.documents.0.fiscal_evidence_artifact_id',
            )
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/clients/'
            .$client->id.'/history?reference_id='.$reference->id,
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.references')
            ->assertJsonCount(1, 'data.documents')
            ->assertJsonPath('data.documents.0.id', $specificArtifact->id)
            ->assertJsonMissingPath(
                'data.documents.0.fiscal_evidence_artifact_id',
            )
            ->assertJsonPath('data.provenance.serpro_called', false);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id
            .'/history?year=1999',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year']);

        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/clients/'
            .$client->id.'/history?reference_id=0',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['reference_id']);

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_read_requests_reject_tenant_input_and_hide_cross_tenant_clients(): void
    {
        Bus::fake();
        Http::fake();
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $this->actingAsTenantUser($viewer);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id
            .'/history?filters[tenant_id]='.$tenant->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/latest-declaration/clients/'
            .$client->id.'/history?filters[tenant_id]='.$tenant->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        $paths = [
            '/api/v1/fiscal/dctfweb/clients/'
            .$otherClient->id.'/history',
            '/api/v1/fiscal/dctfweb/clients/'
            .$otherClient->id.'/communication-preview',
            '/api/v1/fiscal/dctfweb/clients/'
            .$otherClient->id.'/communications',
            '/api/v1/fiscal/simples-mei/defis/clients/'
            .$otherClient->id.'/history',
            '/api/v1/fiscal/simples-mei/defis/latest-declaration/clients/'
            .$otherClient->id.'/history',
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/clients/'
            .$otherClient->id.'/history',
        ];

        foreach ($paths as $path) {
            $this->getJson($path)->assertNotFound();
        }

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_downloads_preserve_documents_headers_and_sanitized_not_found(): void
    {
        Bus::fake();
        Http::fake();
        $tenant = Tenant::factory()->create();
        $otherTenant = Tenant::factory()->create();
        $viewer = User::factory()
            ->forTenant($tenant, TenantRole::TenantUser, 'viewer')
            ->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $run = $this->makeRun($tenant, $client, 'download-local');
        $otherRun = $this->makeRun(
            $otherTenant,
            $otherClient,
            'download-foreign',
        );
        $declaration = $this->makeDctfwebDeclaration(
            $tenant,
            $client,
            '2026-05',
        );
        $otherDeclaration = $this->makeDctfwebDeclaration(
            $otherTenant,
            $otherClient,
            '2026-05',
        );
        $dctfweb = $this->makeDctfwebEvidence(
            $tenant,
            $client,
            $run,
            $declaration,
            '%PDF dctfweb',
        );
        $foreignDctfweb = $this->makeDctfwebEvidence(
            $otherTenant,
            $otherClient,
            $otherRun,
            $otherDeclaration,
            '%PDF foreign dctfweb',
        );
        $reference = $this->makeDefisReference($tenant, $client);
        $otherReference = $this->makeDefisReference(
            $otherTenant,
            $otherClient,
        );
        $latest = $this->makeLatestDefisArtifact(
            $tenant,
            $client,
            $run,
            '%PDF latest',
        );
        $foreignLatest = $this->makeLatestDefisArtifact(
            $otherTenant,
            $otherClient,
            $otherRun,
            '%PDF foreign latest',
        );
        $specific = $this->makeSpecificDefisArtifact(
            $tenant,
            $client,
            $run,
            $reference,
            '%PDF specific',
        );
        $foreignSpecific = $this->makeSpecificDefisArtifact(
            $otherTenant,
            $otherClient,
            $otherRun,
            $otherReference,
            '%PDF foreign specific',
        );
        $this->actingAsTenantUser($viewer);

        $dctfwebResponse = $this->get(
            '/api/v1/fiscal/dctfweb/clients/'.$client->id
            .'/evidence/'.$dctfweb->id.'/download',
        )
            ->assertOk()
            ->assertContent('%PDF dctfweb')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="dctfweb-recibo-'
                .$dctfweb->id.'.pdf"',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertPrivateNoStore(
            (string) $dctfwebResponse->headers->get('Cache-Control'),
        );

        $this->get(
            '/api/v1/fiscal/dctfweb/evidence/'.$dctfweb->id.'/download',
        )
            ->assertOk()
            ->assertContent('%PDF dctfweb');

        $latestResponse = $this->get(
            '/api/v1/fiscal/simples-mei/defis/latest-declaration/artifacts/'
            .$latest->id.'/download',
        )
            ->assertOk()
            ->assertContent('%PDF latest')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="defis-'.$latest->id.'.pdf"',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertPrivateNoStore(
            (string) $latestResponse->headers->get('Cache-Control'),
        );

        $specificResponse = $this->get(
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/artifacts/'
            .$specific->id.'/download',
        )
            ->assertOk()
            ->assertContent('%PDF specific')
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                'attachment; filename="defis-'.$specific->id.'.pdf"',
            )
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Pragma', 'no-cache');
        $this->assertPrivateNoStore(
            (string) $specificResponse->headers->get('Cache-Control'),
        );

        $this->getJson(
            '/api/v1/fiscal/dctfweb/evidence/'
            .$foreignDctfweb->id.'/download',
        )
            ->assertNotFound()
            ->assertDontSee($foreignDctfweb->content_sha256);
        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/latest-declaration/artifacts/'
            .$foreignLatest->id.'/download',
        )
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/artifacts/'
            .$foreignSpecific->id.'/download',
        )
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND');

        $this->objects->forget(
            $specific->evidenceArtifact->vault_object_id,
        );
        $this->getJson(
            '/api/v1/fiscal/simples-mei/defis/specific-declaration/artifacts/'
            .$specific->id.'/download',
        )
            ->assertNotFound()
            ->assertJsonPath('code', 'NOT_FOUND')
            ->assertDontSee($specific->evidenceArtifact->vault_object_id)
            ->assertDontSee($specific->evidenceArtifact->content_sha256);

        $this->assertCount(4, $this->objects->reads);
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function actingAsTenantUser(User $user): void
    {
        Sanctum::actingAs($user);
        app(CurrentTenant::class)->clear();
    }

    private function makeDctfwebDeclaration(
        Tenant $tenant,
        Client $client,
        string $periodKey,
    ): DctfwebDeclaration {
        return DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'period_key' => $periodKey,
                'category' => DctfwebCategory::GeralMensal,
                'declaration_type' => 'ORIGINAL',
                'transmission_status' => DctfwebTransmissionStatus::Transmitted,
                'situation' => FiscalSituation::UpToDate,
                'declaration_state' => DctfwebDeclarationState::Current,
                'coverage' => FiscalCoverage::Full,
                'payment_status' => FiscalPaymentStatus::Unknown,
                'evidence_version' => 1,
                'last_productive_consulted_at' => now(),
                'calendar_verified' => true,
            ]);
    }

    private function makeRun(
        Tenant $tenant,
        Client $client,
        string $key,
    ): FiscalMonitoringRun {
        return FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'system_code' => 'INTEGRA_DCTFWEB',
                'service_code' => 'DCTFWEB',
                'operation_code' => 'CONSULTAR_RECIBO',
                'operation_key' => 'dctfweb.consrecibo',
                'trigger' => FiscalTrigger::Manual,
                'idempotency_key' => $key.'-'.$tenant->id,
                'status' => FiscalRunStatus::Completed,
                'situation' => FiscalSituation::Unknown,
                'coverage' => FiscalCoverage::Partial,
                'mutability' => FiscalMutability::ReadOnly,
                'source_provenance' => FiscalSourceProvenance::SerproTrial,
            ]);
    }

    private function makeDctfwebEvidence(
        Tenant $tenant,
        Client $client,
        FiscalMonitoringRun $run,
        DctfwebDeclaration $declaration,
        string $bytes,
    ): DctfwebEvidenceVersion {
        $artifact = $this->storeEvidence($run, $bytes);

        return DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'declaration_id' => $declaration->id,
                'run_id' => $run->id,
                'evidence_artifact_id' => $artifact->id,
                'artifact_kind' => DctfwebArtifactKind::Recibo,
                'version' => 1,
                'content_sha256' => $artifact->content_sha256,
                'is_current' => true,
                'declaration_type' => 'ORIGINAL',
                'source_version' => 'TEST',
                'is_retification' => false,
                'observed_at' => now(),
                'created_at' => now(),
            ]);
    }

    private function makeDefisReference(
        Tenant $tenant,
        Client $client,
    ): DefisDeclarationReference {
        return DefisDeclarationReference::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'vault_object_id' => '01J'.str_pad(
                    (string) $client->id,
                    23,
                    '0',
                    STR_PAD_LEFT,
                ),
                'observed_at' => now(),
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
            ]);
    }

    private function makeDefisProjection(
        Tenant $tenant,
        Client $client,
        DefisDeclarationReference $reference,
    ): DefisDeclarationProjection {
        return DefisDeclarationProjection::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'calendar_year' => 2026,
                'declaration_type' => '1',
                'last_observed_at' => now(),
                'defis_declaration_reference_id' => $reference->id,
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
            ]);
    }

    private function makeLatestDefisArtifact(
        Tenant $tenant,
        Client $client,
        FiscalMonitoringRun $run,
        string $bytes,
    ): DefisLatestDeclarationArtifact {
        $evidence = $this->storeEvidence($run, $bytes);

        return DefisLatestDeclarationArtifact::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'calendar_year' => 2026,
                'kind' => 'DECLARATION',
                'fiscal_evidence_artifact_id' => $evidence->id,
                'source_run_id' => $run->id,
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
                'observed_at' => now(),
                'filename' => 'defis-latest.pdf',
                'content_type' => 'application/pdf',
                'digest' => $evidence->content_sha256,
            ]);
    }

    private function makeSpecificDefisArtifact(
        Tenant $tenant,
        Client $client,
        FiscalMonitoringRun $run,
        DefisDeclarationReference $reference,
        string $bytes,
    ): DefisSpecificDeclarationArtifact {
        $evidence = $this->storeEvidence($run, $bytes);

        return DefisSpecificDeclarationArtifact::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'defis_declaration_reference_id' => $reference->id,
                'kind' => 'DECLARATION',
                'fiscal_evidence_artifact_id' => $evidence->id,
                'source_run_id' => $run->id,
                'source_provenance' => FiscalSourceProvenance::SerproTrial->value,
                'observed_at' => now(),
                'filename' => 'defis-specific.pdf',
                'content_type' => 'application/pdf',
                'digest' => $evidence->content_sha256,
            ]);
    }

    private function storeEvidence(
        FiscalMonitoringRun $run,
        string $bytes,
    ): FiscalEvidenceArtifact {
        return app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: $bytes,
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
    }

    private function assertPrivateNoStore(string $cacheControl): void
    {
        $this->assertStringContainsString('private', $cacheControl);
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
    }
}

final class FiscalDeclarationReadObjectStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    /** @var list<string> */
    public array $reads = [];

    private int $sequence = 0;

    public function put(string $plaintext, array $metadata = []): string
    {
        $this->sequence++;
        $id = '01J'.str_pad(
            (string) $this->sequence,
            23,
            '0',
            STR_PAD_LEFT,
        );
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
        $this->reads[] = $objectId;
        if (! isset($this->objects[$objectId])) {
            throw new RuntimeException('Objeto não encontrado.');
        }

        return $this->objects[$objectId];
    }

    public function delete(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }

    public function exists(string $objectId): bool
    {
        return isset($this->objects[$objectId]);
    }

    public function forget(string $objectId): void
    {
        unset($this->objects[$objectId]);
    }
}
