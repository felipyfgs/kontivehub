<?php

namespace Tests\Feature;

use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Enums\DctfwebTransmissionStatus;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalPaymentStatus;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalTrigger;
use App\Enums\MitEncerramentoStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\MitAssessment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FiscalDeclarationReadApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_reads_empty_dctfweb_and_mit_paginations(): void
    {
        [$tenant, $viewer] = $this->actor();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/dctfweb/declarations?per_page=20')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('per_page', 20);
        $this->getJson('/api/v1/fiscal/mit/apuracoes?per_page=20')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('per_page', 20);

        $this->getJson('/api/v1/fiscal/dctfweb/declarations?tenant_id='.$tenant->id)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['tenant_id']);
    }

    public function test_declaration_filters_and_missing_details_fail_closed(): void
    {
        [, $viewer] = $this->actor();
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/fiscal/dctfweb/declarations?per_page=101')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['per_page']);
        $this->getJson('/api/v1/fiscal/mit/apuracoes?client_id=invalid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['client_id']);
        $this->getJson('/api/v1/fiscal/dctfweb/declarations/999999')
            ->assertNotFound();
        $this->getJson('/api/v1/fiscal/mit/apuracoes/999999')
            ->assertNotFound();
    }

    public function test_dctfweb_resources_preserve_contract_and_tenant_isolation(): void
    {
        Bus::fake();
        Http::fake();
        [$tenant, $viewer] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $declaration = $this->declaration($tenant, $client, '2026-06');
        $foreign = $this->declaration(
            $otherTenant,
            $otherClient,
            '2026-07',
        );
        $version = $this->evidenceVersion(
            $tenant,
            $client,
            $declaration,
        );
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/dctfweb/declarations?client_id='.$client->id
            .'&per_page=1',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $declaration->toPublicArray())
            ->assertJsonMissing(['id' => $foreign->id])
            ->assertJsonMissingPath('meta');

        $this->getJson(
            '/api/v1/fiscal/dctfweb/declarations/'.$declaration->id,
        )
            ->assertOk()
            ->assertExactJson([
                'data' => $declaration->toPublicArray(),
                'evidence_versions' => [$version->toPublicArray()],
            ])
            ->assertJsonMissingPath(
                'evidence_versions.0.vault_object_id',
            );

        $this->getJson(
            '/api/v1/fiscal/dctfweb/declarations/'.$foreign->id,
        )->assertNotFound();

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_mit_resources_preserve_contract_and_tenant_isolation(): void
    {
        Bus::fake();
        Http::fake();
        [$tenant, $viewer] = $this->actor();
        $otherTenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        $otherClient = Client::factory()->for($otherTenant)->create();
        $assessment = $this->assessment($tenant, $client, '2026-06');
        $foreign = $this->assessment(
            $otherTenant,
            $otherClient,
            '2026-07',
        );
        Sanctum::actingAs($viewer);

        $this->getJson(
            '/api/v1/fiscal/mit/apuracoes?client_id='.$client->id
            .'&per_page=1',
        )
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0', $assessment->toPublicArray())
            ->assertJsonMissing(['id' => $foreign->id])
            ->assertJsonMissingPath('data.0.metadata')
            ->assertJsonMissingPath('meta');

        $this->getJson('/api/v1/fiscal/mit/apuracoes/'.$assessment->id)
            ->assertOk()
            ->assertExactJson(['data' => $assessment->toPublicArray()]);

        $this->getJson(
            '/api/v1/fiscal/mit/lista-apuracoes?client_id='.$client->id
            .'&year=2026',
        )
            ->assertOk()
            ->assertExactJson([
                'data' => [$assessment->toPublicArray()],
                'provenance' => [
                    'source' => 'LOCAL_PROJECTION',
                    'serpro_called' => false,
                ],
            ]);

        $this->getJson('/api/v1/fiscal/mit/apuracoes/'.$foreign->id)
            ->assertNotFound();
        $this->getJson(
            '/api/v1/fiscal/mit/lista-apuracoes?client_id='
            .$otherClient->id,
        )->assertNotFound();
        $this->getJson(
            '/api/v1/fiscal/mit/lista-apuracoes?client_id='.$client->id
            .'&year=1999',
        )
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['year']);
        $this->getJson(
            '/api/v1/fiscal/mit/lista-apuracoes?client_id='.$client->id
            .'&tenant_id='.$tenant->id,
        )
            ->assertUnprocessable()
            ->assertJsonPath('code', 'CLIENT_TENANT_ID_REJECTED');

        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @return array{Tenant, User} */
    private function actor(): array
    {
        $tenant = Tenant::factory()->create();
        $viewer = User::factory()->forTenant($tenant, TenantRole::TenantUser, 'viewer')->create();

        return [$tenant, $viewer];
    }

    private function declaration(
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
                'calendar_verified' => true,
            ]);
    }

    private function evidenceVersion(
        Tenant $tenant,
        Client $client,
        DctfwebDeclaration $declaration,
    ): DctfwebEvidenceVersion {
        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'system_code' => 'INTEGRA_DCTFWEB',
                'service_code' => 'DCTFWEB',
                'operation_code' => 'CONSULTAR_RECIBO',
                'trigger' => FiscalTrigger::Manual,
                'idempotency_key' => 'dctfweb-resource-'.$declaration->id,
                'status' => FiscalRunStatus::Completed,
                'situation' => FiscalSituation::UpToDate,
                'coverage' => FiscalCoverage::Full,
                'source_provenance' => FiscalSourceProvenance::SerproTrial,
            ]);
        $artifact = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'run_id' => $run->id,
                'vault_object_id' => '01J'.str_pad(
                    (string) $client->id,
                    23,
                    '0',
                    STR_PAD_LEFT,
                ),
                'content_sha256' => hash(
                    'sha256',
                    'dctfweb-'.$declaration->id,
                ),
                'content_type' => 'application/pdf',
                'byte_size' => 256,
                'source' => 'SERPRO',
                'observed_at' => now(),
            ]);

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

    private function assessment(
        Tenant $tenant,
        Client $client,
        string $periodKey,
    ): MitAssessment {
        $assessment = MitAssessment::query()
            ->withoutGlobalScopes()
            ->create([
                'tenant_id' => $tenant->id,
                'client_id' => $client->id,
                'period_key' => $periodKey,
                'encerramento_status' => MitEncerramentoStatus::Open,
                'situacao_status' => 'PENDING',
                'dctfweb_transmission_status' => DctfwebTransmissionStatus::Unknown,
                'situation' => FiscalSituation::Pending,
                'coverage' => FiscalCoverage::Partial,
                'observed_at' => now(),
                'metadata' => [
                    'lista_apuracoes_317' => [
                        'id_apuracao' => 317,
                        'period_key' => $periodKey,
                    ],
                    'internal_payload' => 'must-not-leak',
                ],
            ]);

        return MitAssessment::query()
            ->withoutGlobalScopes()
            ->findOrFail($assessment->id);
    }
}
