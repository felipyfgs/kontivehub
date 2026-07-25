<?php

namespace Tests\Unit\Integra\Mailbox;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Enums\FiscalProfile;
use App\Enums\FiscalRunStatus;
use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalModuleControl;
use App\Models\FiscalMonitoringRun;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxDetailEnqueueService;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxDetailEnqueueServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'fiscal.profile' => FiscalProfile::Dev->value,
            'fiscal.kill_switch' => false,
            'fiscal_monitoring.mailbox.max_detail_fetches_per_sync' => 2,
        ]);
    }

    public function test_enqueues_up_to_configured_limit(): void
    {
        Queue::fake();
        [$office, $client] = $this->seedTenant();

        foreach (['A', 'B', 'C'] as $i => $ext) {
            $this->seedMessage($office, $client, $ext, unread: $i === 0);
        }

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($office, $client);

        $this->assertCount(2, $runs);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 2);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 2);
    }

    public function test_fail_closed_when_module_restricted(): void
    {
        Queue::fake();
        [$office, $client] = $this->seedTenant();
        $this->seedMessage($office, $client, 'X');

        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Office,
            'office_id' => $office->id,
            'restricted' => true,
            'reason' => 'Pausa teste',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($office, $client);

        $this->assertSame([], $runs);
        Queue::assertNothingPushed();
    }

    public function test_skips_when_open_detail_run_exists(): void
    {
        Queue::fake();
        [$office, $client] = $this->seedTenant();
        $msg = $this->seedMessage($office, $client, 'DUP');

        FiscalMonitoringRun::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'INTEGRA_CAIXAPOSTAL',
            'service_code' => 'CAIXA_POSTAL',
            'operation_code' => 'DETALHE',
            'operation_key' => 'caixa_postal.detalhe',
            'trigger' => 'EVENT',
            'idempotency_key' => 'open-detail-'.$msg->id,
            'status' => FiscalRunStatus::Queued,
            'situation' => 'UNKNOWN',
            'coverage' => 'UNKNOWN',
            'mutability' => 'READ_ONLY',
            'correlation_id' => 'corr-open',
            'progress' => [
                'external_message_id' => 'EXT-DUP',
                'message_id' => $msg->id,
            ],
        ]);

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($office, $client);

        $this->assertSame([], $runs);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_zero_limit_disables_enqueue(): void
    {
        Queue::fake();
        config(['fiscal_monitoring.mailbox.max_detail_fetches_per_sync' => 0]);
        [$office, $client] = $this->seedTenant();
        $this->seedMessage($office, $client, 'Z');

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($office, $client);

        $this->assertSame([], $runs);
        Queue::assertNothingPushed();
    }

    /** @return array{0: Office, 1: Client} */
    private function seedTenant(): array
    {
        $office = Office::factory()->create(['is_active' => true]);
        $client = Client::factory()->for($office)->create();

        return [$office, $client];
    }

    private function seedMessage(Office $office, Client $client, string $suffix, bool $unread = true): MailboxMessage
    {
        $externalId = 'EXT-'.$suffix;

        return MailboxMessage::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $office->id, (int) $client->id, $externalId),
            'source' => MailboxSource::CaixaPostal,
            'sensitivity_class' => 'FISCAL_RESTRICTED',
            'subject_preview' => 'Assunto '.$suffix,
            'received_at_official' => now()->subDays(strlen($suffix)),
            'official_read_indicator' => ! $unread,
            'triage_status' => MailboxTriageStatus::New,
            'has_body' => false,
            'attachment_count' => 0,
        ]);
    }
}
