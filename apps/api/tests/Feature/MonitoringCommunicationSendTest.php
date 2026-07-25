<?php

namespace Tests\Feature;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalMutability;
use App\Enums\FiscalRunStatus;
use App\Enums\FiscalSituation;
use App\Enums\FiscalTrigger;
use App\Enums\OfficeRole;
use App\Enums\TaxObligationApplicability;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientCommunicationDispatch;
use App\Models\ClientCommunicationPreference;
use App\Models\ClientContact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Models\PgdasdArtifact;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Models\User;
use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdCommunicationService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\FiscalMonitoringRunService;
use App\Support\CurrentOffice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use ReflectionMethod;
use Tests\TestCase;

class MonitoringCommunicationSendTest extends TestCase
{
    use RefreshDatabase;

    public function test_send_without_local_documents_returns_422(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => false,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        $this->postJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-send")
            ->assertStatus(422);

        $this->assertDatabaseMissing('client_communication_dispatches', [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'submodule_key' => 'pgdasd',
        ]);
    }

    public function test_send_queues_dispatch_with_provider_fail_closed(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        $this->seedPgdasdArtifact($office, $client);

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => false,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        config(['fiscal_monitoring.communication.provider_enabled' => false]);

        $this->postJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-send")
            ->assertOk()
            ->assertJsonPath('data.provider_enabled', false)
            ->assertJsonPath('data.queued', 1);

        $this->assertDatabaseHas('client_communication_dispatches', [
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => 'simples_mei',
            'submodule_key' => 'pgdasd',
            'status' => 'QUEUED',
        ]);
    }

    public function test_preference_patch_persists_automatic_requested(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();

        $this->patchJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-preference", [
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'automatic_requested' => true,
            'lock_version' => 0,
        ])
            ->assertOk()
            ->assertJsonPath('data.automatic_requested', true);
    }

    public function test_automatic_hook_ignores_non_pgdasd_simples_mei_services(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        $this->seedPgdasdArtifact($office, $client);

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => true,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        $runner = app(FiscalMonitoringRunService::class);
        $method = new ReflectionMethod(FiscalMonitoringRunService::class, 'maybeQueueAutomaticCommunication');
        $method->invoke($runner, $office, $client, 'simples_mei', 'DEFIS142');

        $this->assertSame(
            0,
            ClientCommunicationDispatch::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('submodule_key', 'pgdasd')
                ->count()
        );
    }

    public function test_automatic_hook_skips_pgdasd_without_local_documents(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => true,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        app(PgdasdCommunicationService::class)->maybeQueueAutomaticAfterConsult($office, $client);

        $this->assertSame(
            0,
            ClientCommunicationDispatch::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->count()
        );
    }

    public function test_automatic_hook_queues_short_idempotency_and_dedupes_period(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        $this->seedPgdasdArtifact($office, $client);

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => true,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        $service = app(PgdasdCommunicationService::class);
        $service->maybeQueueAutomaticAfterConsult($office, $client);
        $service->maybeQueueAutomaticAfterConsult($office, $client);

        $dispatches = ClientCommunicationDispatch::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->get();

        $this->assertCount(1, $dispatches);
        $key = (string) $dispatches->first()->idempotency_key;
        $this->assertLessThanOrEqual(64, strlen($key));
        $this->assertStringContainsString(':auto', $key);
    }

    public function test_manual_send_is_requeueable_with_short_idempotency(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        $this->seedPgdasdArtifact($office, $client);

        ClientCommunicationPreference::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'module_key' => PgdasdCommunicationService::MODULE,
            'submodule_key' => PgdasdCommunicationService::SUBMODULE,
            'automatic_requested' => false,
            'email_enabled' => true,
            'whatsapp_enabled' => false,
            'lock_version' => 1,
            'updated_by_user_id' => $user->id,
        ]);

        config(['fiscal_monitoring.communication.provider_enabled' => false]);

        $this->postJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-send")
            ->assertOk()
            ->assertJsonPath('data.queued', 1);
        $this->postJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-send")
            ->assertOk()
            ->assertJsonPath('data.queued', 1);

        $dispatches = ClientCommunicationDispatch::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->get();

        $this->assertCount(2, $dispatches);
        foreach ($dispatches as $dispatch) {
            $this->assertLessThanOrEqual(64, strlen((string) $dispatch->idempotency_key));
            $this->assertStringContainsString(':man:', (string) $dispatch->idempotency_key);
        }
        $this->assertNotSame(
            $dispatches[0]->idempotency_key,
            $dispatches[1]->idempotency_key,
        );
    }

    public function test_preview_and_tracking_are_readable(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        $this->seedPgdasdArtifact($office, $client);

        $this->getJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communication-preview")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->getJson("/api/v1/fiscal/simples-mei/pgdasd/clients/{$client->id}/communications")
            ->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_artifact_download_succeeds_and_cross_tenant_is_denied(): void
    {
        [$office, $user, $client] = $this->seedReadyClient();
        Sanctum::actingAs($user);
        app(CurrentOffice::class)->clear();
        $artifactId = $this->seedPgdasdArtifact($office, $client);

        $this->get("/api/v1/fiscal/simples-mei/pgdasd/artifacts/{$artifactId}/download")
            ->assertOk();

        $otherOffice = Office::factory()->create();
        $otherUser = User::factory()->forOffice($otherOffice, OfficeRole::Operator)->create();
        Sanctum::actingAs($otherUser);
        app(CurrentOffice::class)->clear();

        $this->getJson("/api/v1/fiscal/simples-mei/pgdasd/artifacts/{$artifactId}/download")
            ->assertNotFound();
    }

    /**
     * @return array{0: Office, 1: User, 2: Client}
     */
    private function seedReadyClient(): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->for($office)->create([
            'is_active' => true,
            'matrix_client_id' => null,
            'tax_regime' => TaxRegimeCode::SimplesNacional->value,
        ]);
        ClientContact::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'email' => 'ops@example.com',
            'is_active' => true,
            'receives_alerts' => true,
        ]);

        return [$office, $user, $client];
    }

    private function seedPgdasdArtifact(Office $office, Client $client): int
    {
        $def = TaxObligationDefinition::query()->firstOrCreate(
            ['code' => 'PGDAS_D'],
            [
                'name' => 'PGDAS-D',
                'system_code' => 'INTEGRA_SN',
                'service_code' => 'PGDASD',
                'is_active' => true,
                'sort_order' => 10,
            ],
        );
        $projection = TaxObligationProjection::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'obligation_definition_id' => $def->id,
            'period_key' => '2026-06',
            'period_year' => 2026,
            'period_month' => 6,
            'is_open' => true,
            'situation' => FiscalSituation::Pending,
            'delivery_status' => FiscalSituation::Pending,
            'applicability' => TaxObligationApplicability::Applicable,
        ]);

        $run = FiscalMonitoringRun::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'MONITOR',
            'operation_key' => 'pgdasd.monitor',
            'trigger' => FiscalTrigger::Manual,
            'idempotency_key' => 'comm-artifact:'.fake()->uuid(),
            'status' => FiscalRunStatus::Completed,
            'situation' => FiscalSituation::Unknown,
            'coverage' => FiscalCoverage::Partial,
            'mutability' => FiscalMutability::ReadOnly,
        ]);

        $evidence = app(FiscalEvidenceStore::class)->store(
            run: $run,
            bytes: '%PDF-1.4 test',
            contentType: 'application/pdf',
            source: 'SERPRO',
        );

        $artifact = PgdasdArtifact::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'projection_id' => $projection->id,
            'fiscal_evidence_artifact_id' => $evidence->id,
            'kind' => 'DECLARATION_PDF',
            'filename' => 'decl.pdf',
            'content_type' => 'application/pdf',
            'observed_at' => now(),
            'source_run_id' => $run->id,
        ]);

        return (int) $artifact->id;
    }
}
