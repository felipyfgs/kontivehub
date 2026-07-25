<?php

namespace Tests\Feature;

use App\Enums\MailboxEventProcessingStatus;
use App\Enums\SerproEnvironment;
use App\Enums\SubscriptionStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\ClientProcuracaoSync;
use App\Models\Establishment;
use App\Models\MailboxClientSyncState;
use App\Models\Office;
use App\Models\OfficeSubscription;
use App\Models\SerproEventosRun;
use App\Services\Integra\Eventos\EventosResultArtifactStore;
use App\Services\Integra\Mailbox\MailboxEventosResultProcessor;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class MailboxEventosResultProcessorTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_past_event_is_directed_once_and_retry_is_local_and_idempotent(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-07-21 10:00:00', 'America/Sao_Paulo');
        [$office, $client, $cnpj] = $this->eligibleClient();
        $run = $this->consumedRun($office, [[$cnpj, '260720']]);

        $processor = app(MailboxEventosResultProcessor::class);
        $processed = $processor->process($run);
        $processor->process($processed->fresh());

        $this->assertSame(MailboxEventosResultProcessor::LOCAL_SUCCEEDED, $processed->local_processing_status);
        $this->assertDatabaseCount('serpro_eventos_run_items', 1);
        $this->assertDatabaseCount('fiscal_last_update_events', 1);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 1);
        $this->assertDatabaseHas('serpro_eventos_run_items', [
            'serpro_eventos_run_id' => $run->id,
            'client_id' => $client->id,
            'processing_status' => MailboxEventProcessingStatus::Directed->value,
        ]);
        $state = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $client->id)->firstOrFail();
        $this->assertSame('2026-07-20', $state->pending_event_date?->toDateString());
        $this->assertNull($state->last_reconciled_event_date);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
        $this->assertArrayNotHasKey('result_vault_object_id', $processed->toSanitizedArray());
    }

    public function test_current_day_stays_pending_and_denied_item_is_isolated(): void
    {
        Queue::fake();
        CarbonImmutable::setTestNow('2026-07-21 10:00:00', 'America/Sao_Paulo');
        [$office, $currentClient, $currentCnpj] = $this->eligibleClient();
        $deniedClient = Client::factory()->for($office)->create();
        $deniedCnpj = '11365521000169';
        Establishment::factory()->forClient($deniedClient, $deniedCnpj)->create();
        ClientProcuracaoSync::factory()->forClient($deniedClient)->authorized()->create();
        $run = $this->consumedRun($office, [[$currentCnpj, '260721'], [$deniedCnpj, 'x']]);

        app(MailboxEventosResultProcessor::class)->process($run);

        $this->assertDatabaseCount('fiscal_monitoring_runs', 0);
        $currentState = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('client_id', $currentClient->id)->firstOrFail();
        $this->assertSame('2026-07-21', $currentState->pending_event_date?->toDateString());
        $this->assertDatabaseHas('mailbox_client_sync_states', [
            'client_id' => $deniedClient->id,
            'authorization_status' => 'DENIED',
            'last_error_code' => 'EVENTOS_ACCESS_DENIED',
        ]);
    }

    public function test_missing_private_artifact_fails_without_remote_egress(): void
    {
        $office = Office::factory()->create();
        $run = SerproEventosRun::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'person_type' => 'PJ',
            'phase' => SerproEventosRun::PHASE_CONSUMED,
            'status' => SerproEventosRun::STATUS_RUNNING,
            'evento' => 'E0601',
            'result_consumed' => true,
            'one_shot_complete' => true,
            'local_processing_status' => MailboxEventosResultProcessor::LOCAL_PENDING,
            'result_vault_object_id' => '01J00000000000000000000000',
            'result_payload_sha256' => hash('sha256', 'missing'),
        ]);

        try {
            app(MailboxEventosResultProcessor::class)->process($run);
            $this->fail('Era esperado erro de artefato ausente.');
        } catch (RuntimeException $e) {
            $this->assertSame('EVENTOS_RESULT_ARTIFACT_MISSING', $e->getMessage());
        }
        $this->assertSame(MailboxEventosResultProcessor::LOCAL_FAILED, $run->fresh()->local_processing_status);
    }

    /** @return array{Office,Client,string} */
    private function eligibleClient(): array
    {
        $office = Office::factory()->create();
        OfficeSubscription::query()->where('office_id', $office->id)->update([
            'status' => SubscriptionStatus::Active->value,
        ]);
        $client = Client::factory()->for($office)->create();
        $cnpj = '11222333000181';
        Establishment::factory()->forClient($client, $cnpj)->create();
        ClientProcuracaoSync::factory()->forClient($client)->authorized()->create();

        return [$office, $client, $cnpj];
    }

    private function consumedRun(Office $office, array $dados): SerproEventosRun
    {
        $run = SerproEventosRun::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'person_type' => 'PJ',
            'phase' => SerproEventosRun::PHASE_CONSUMED,
            'status' => SerproEventosRun::STATUS_RUNNING,
            'evento' => 'E0601',
            'result_consumed' => true,
            'one_shot_complete' => true,
            'local_processing_status' => MailboxEventosResultProcessor::LOCAL_PENDING,
        ]);
        $artifact = app(EventosResultArtifactStore::class)->store($run, $dados);
        $run->forceFill([
            'result_vault_object_id' => $artifact['object_id'],
            'result_payload_sha256' => $artifact['sha256'],
            'remote_result_received_at' => now(),
        ])->save();

        return $run->fresh();
    }
}
