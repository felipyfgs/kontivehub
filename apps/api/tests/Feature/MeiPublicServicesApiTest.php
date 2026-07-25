<?php

namespace Tests\Feature;

use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\MeiAutomationAttempt;
use App\Models\Office;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class MeiPublicServicesApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_pgmei_consult_accepts_legacy_year_without_leaking_tenant(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Operator);
        $client = Client::factory()->forOffice($office)->create();
        config(['fiscal_monitoring.enabled' => true]);
        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/pgmei/consult', [
            'client_ids' => [$client->id],
            'year' => 2025,
            'confirmed' => true,
        ])->assertCreated()
            ->assertJsonPath('calendar_year', 2025)
            ->assertJsonMissingPath('data.0.office_id');

        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }

    public function test_dasn_history_preserves_summary_coverage_and_tenant_scope(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Viewer);
        $client = Client::factory()->forOffice($office)->create();
        $attempt = $this->dasnAttempt($office, $client);
        Sanctum::actingAs($user);

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history?calendar_year=2025')
            ->assertOk()
            ->assertJsonPath('data.coverage', 'SUMMARY')
            ->assertJsonPath('data.declarations.0.calendar_year', 2025)
            ->assertJsonPath('data.declarations.0.pending', true)
            ->assertJsonPath('data.declarations.0.declaration_type', 'Original')
            ->assertJsonPath('data.declarations.0.special_situation', 'Extinção')
            ->assertJsonPath('data.declarations.0.special_situation_date', '2026-05-20')
            ->assertJsonPath('data.declarations.0.receipt_available', false)
            ->assertJsonPath('data.pending_years.0', 2025)
            ->assertJsonCount(1, 'data.pending_years')
            ->assertJsonPath('data.attempt.id', $attempt->id)
            ->assertJsonMissingPath('data.attempt.office_id')
            ->assertJsonMissingPath('data.attempt.result_payload_encrypted');

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history')
            ->assertOk()
            ->assertJsonPath('data.pending_years.0', 2024)
            ->assertJsonPath('data.pending_years.1', 2025)
            ->assertJsonCount(2, 'data.pending_years');

        $queued = MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'operation_key' => 'dasnsimei.consultimadecrec',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Queued,
            'idempotency_key' => 'dasn:'.str_repeat('q', 12),
            'request_fingerprint' => str_repeat('c', 64),
        ]);

        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$client->id.'/history?calendar_year=2025')
            ->assertOk()
            ->assertJsonPath('data.coverage', 'SUMMARY')
            ->assertJsonPath('data.declarations.0.calendar_year', 2025)
            ->assertJsonPath('data.attempt.id', $queued->id)
            ->assertJsonPath('data.attempt.status', 'QUEUED');

        $otherClient = Client::factory()->forOffice(Office::factory()->create())->create();
        $this->getJson('/api/v1/fiscal/simples-mei/dasn-simei/clients/'.$otherClient->id.'/history')
            ->assertNotFound();
    }

    public function test_dasn_consult_validates_whole_batch_before_dispatch(): void
    {
        [$user, $office] = $this->actor(OfficeRole::Operator);
        $client = Client::factory()->forOffice($office)->create();
        $otherClient = Client::factory()->forOffice(Office::factory()->create())->create();
        config(['fiscal_monitoring.enabled' => true]);
        Queue::fake();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/dasn-simei/consult', [
            'client_ids' => [$client->id, $otherClient->id],
            'calendar_year' => 2025,
            'confirmed' => true,
        ])->assertUnprocessable();
        Queue::assertNothingPushed();

        $this->postJson('/api/v1/fiscal/simples-mei/dasn-simei/consult', [
            'client_ids' => [$client->id],
            'calendar_year' => 2025,
            'include_full_receipt' => false,
            'confirmed' => true,
        ])->assertCreated()
            ->assertJsonPath('enqueued_count', 1)
            ->assertJsonMissingPath('data.0.office_id')
            ->assertJsonMissingPath('data.0.idempotency_key');
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }

    public function test_das_preflight_hides_cross_office_client(): void
    {
        [$user] = $this->actor(OfficeRole::Admin);
        $otherClient = Client::factory()->forOffice(Office::factory()->create())->create();
        Sanctum::actingAs($user);

        $this->postJson('/api/v1/fiscal/simples-mei/pgmei/das/preflight', [
            'client_id' => $otherClient->id,
            'competencies' => ['2025-01'],
            'output_format' => 'PDF',
            'idempotency_key' => 'das-cross-office',
        ])->assertNotFound();
    }

    /** @return array{User, Office} */
    private function actor(OfficeRole $role): array
    {
        $office = Office::factory()->create();
        $user = User::factory()->forOffice($office, $role)->create();

        return [$user, $office];
    }

    private function dasnAttempt(Office $office, Client $client): MeiAutomationAttempt
    {
        return MeiAutomationAttempt::query()->withoutGlobalScopes()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'operation_key' => 'dasnsimei.consultimadecrec',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Succeeded,
            'idempotency_key' => 'dasn:'.str_repeat('a', 12),
            'request_fingerprint' => str_repeat('b', 64),
            'result_payload_encrypted' => [
                'coverage' => 'SUMMARY',
                'declarations' => [[
                    'calendar_year' => 2025,
                    'status' => 'NÃO APRESENTADA',
                    'transmitted_at' => null,
                    'declaration_type' => 'Original',
                    'special_situation' => 'Extinção',
                    'special_situation_date' => '2026-05-20',
                    'pending' => true,
                    'coverage' => 'SUMMARY',
                    'receipt_available' => false,
                    'receipt_artifact_id' => null,
                ], [
                    'calendar_year' => 2024,
                    'status' => 'NÃO APRESENTADA',
                    'transmitted_at' => null,
                    'declaration_type' => 'Original',
                    'special_situation' => null,
                    'special_situation_date' => null,
                    'pending' => true,
                    'coverage' => 'SUMMARY',
                    'receipt_available' => false,
                    'receipt_artifact_id' => null,
                ]],
                'parser_version' => 'fixture-v1',
                'portal_version' => 'fixture-v1',
            ],
        ]);
    }
}
