<?php

namespace Tests\Feature;

use App\Contracts\SecureObjectStore;
use App\Enums\DocumentUnavailableReason;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\FiscalVerificationState;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\User;
use App\Services\FiscalMonitoring\FiscalDocumentDescriptorFactory;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\Surfaces\MonitoringSurfaceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

class FiscalEvidenceAccessTest extends TestCase
{
    use RefreshDatabase;

    private InMemoryEvidenceStore $objects;

    protected function setUp(): void
    {
        parent::setUp();
        $this->objects = new InMemoryEvidenceStore;
        $this->app->instance(SecureObjectStore::class, $this->objects);
    }

    public function test_descriptor_and_download_require_a_real_integral_current_office_artifact(): void
    {
        $office = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $client = Client::factory()->for($office)->create();
        $artifact = $this->storeArtifact($office, $client, '%PDF-1.7 evidence');
        Sanctum::actingAs($viewer);

        $surface = app(MonitoringSurfaceRegistry::class)->get('dctfweb');
        $descriptor = app(FiscalDocumentDescriptorFactory::class)
            ->forSurface($office, $surface, $artifact)
            ->toArray();
        $this->assertTrue($descriptor['available']);
        $this->assertSame('PDF', $descriptor['kind']);
        $this->assertSame('/api/v1/fiscal/evidence/'.$artifact->id.'/download', $descriptor['href']);

        $this->get('/api/v1/fiscal/evidence/'.$artifact->id.'/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertStreamedContent('%PDF-1.7 evidence');

        $this->objects->forget($artifact->vault_object_id);
        $missing = app(FiscalDocumentDescriptorFactory::class)
            ->forSurface($office, $surface, $artifact->fresh())
            ->toArray();
        $this->assertFalse($missing['available']);
        $this->assertNull($missing['href']);
        $this->assertSame(DocumentUnavailableReason::NotAvailable->value, $missing['unavailable_reason']);
        $this->get('/api/v1/fiscal/evidence/'.$artifact->id.'/download')->assertNotFound();
    }

    public function test_cross_tenant_and_rejected_evidence_fail_closed_without_href_or_metadata_leak(): void
    {
        $office = Office::factory()->create();
        $viewer = User::factory()->forOffice($office, OfficeRole::Viewer)->create();
        $otherOffice = Office::factory()->create();
        $otherClient = Client::factory()->for($otherOffice)->create();
        $otherArtifact = $this->storeArtifact($otherOffice, $otherClient, '%PDF other tenant');
        Sanctum::actingAs($viewer);

        $surface = app(MonitoringSurfaceRegistry::class)->get('dctfweb');
        $crossTenant = app(FiscalDocumentDescriptorFactory::class)
            ->forSurface($office, $surface, $otherArtifact)
            ->toArray();
        $this->assertFalse($crossTenant['available']);
        $this->assertNull($crossTenant['href']);
        $this->assertSame(DocumentUnavailableReason::NotAvailable->value, $crossTenant['unavailable_reason']);
        $this->getJson('/api/v1/fiscal/evidence/'.$otherArtifact->id.'/download')
            ->assertNotFound()
            ->assertJsonMissingPath('data');

        $otherArtifact->forceFill([
            'verification_state' => FiscalVerificationState::Rejected,
        ])->save();
        $rejected = app(FiscalDocumentDescriptorFactory::class)
            ->forSurface($otherOffice, $surface, $otherArtifact->fresh())
            ->toArray();
        $this->assertFalse($rejected['available']);
        $this->assertNull($rejected['href']);
        $this->assertSame(
            DocumentUnavailableReason::IntegrityRejected->value,
            $rejected['unavailable_reason'],
        );
    }

    public function test_structured_and_aggregate_surfaces_never_fabricate_document_links(): void
    {
        $office = Office::factory()->create();
        $factory = app(FiscalDocumentDescriptorFactory::class);
        $surfaces = app(MonitoringSurfaceRegistry::class);

        $structured = $factory->forSurface(
            $office,
            $surfaces->get('fgts'),
        )->toArray();
        $aggregate = $factory->forSurface(
            $office,
            $surfaces->get('monitoring_dashboard'),
        )->toArray();

        $this->assertFalse($structured['available']);
        $this->assertNull($structured['href']);
        $this->assertSame('STRUCTURED_ONLY', $structured['unavailable_reason']);
        $this->assertFalse($aggregate['available']);
        $this->assertNull($aggregate['href']);
        $this->assertSame('NOT_SUPPORTED', $aggregate['unavailable_reason']);
    }

    private function storeArtifact(
        Office $office,
        Client $client,
        string $bytes,
    ): FiscalEvidenceArtifact {
        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_DCTFWEB',
            'service_code' => 'DCTFWEB',
            'operation_code' => 'CONSULTAR_RECIBO',
            'operation_key' => 'dctfweb.consrecibo',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'evidence-access:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        return app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: $bytes,
            contentType: 'application/pdf',
            source: 'SERPRO',
        );
    }
}

final class InMemoryEvidenceStore implements SecureObjectStore
{
    /** @var array<string, string> */
    private array $objects = [];

    private int $sequence = 0;

    public function put(string $plaintext, array $metadata = []): string
    {
        $this->sequence++;
        $id = '01J'.str_pad((string) $this->sequence, 23, '0', STR_PAD_LEFT);
        $this->objects[$id] = $plaintext;

        return $id;
    }

    public function get(string $objectId, array $metadata = []): string
    {
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
