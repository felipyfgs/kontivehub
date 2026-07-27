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
use App\Models\Tenant;
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
        [$tenant, $client] = $this->seedTenant();

        foreach (['A', 'B', 'C'] as $i => $ext) {
            $this->seedMessage($tenant, $client, $ext, unread: $i === 0);
        }

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($tenant, $client);

        $this->assertCount(2, $runs);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 2);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 2);
    }

    public function test_fail_closed_when_module_restricted(): void
    {
        Queue::fake();
        [$tenant, $client] = $this->seedTenant();
        $this->seedMessage($tenant, $client, 'X');

        FiscalModuleControl::query()->create([
            'module_key' => FiscalControlModule::Mailbox,
            'scope' => FiscalModuleControlScope::Tenant,
            'tenant_id' => $tenant->id,
            'restricted' => true,
            'reason' => 'Pausa teste',
            'updated_by_user_id' => User::factory()->create()->id,
        ]);

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($tenant, $client);

        $this->assertSame([], $runs);
        Queue::assertNothingPushed();
    }

    public function test_skips_when_open_detail_run_exists(): void
    {
        Queue::fake();
        [$tenant, $client] = $this->seedTenant();
        $msg = $this->seedMessage($tenant, $client, 'DUP');

        FiscalMonitoringRun::query()->create([
            'tenant_id' => $tenant->id,
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

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($tenant, $client);

        $this->assertSame([], $runs);
        $this->assertDatabaseCount('fiscal_monitoring_runs', 1);
        Queue::assertNothingPushed();
    }

    public function test_zero_limit_disables_enqueue(): void
    {
        Queue::fake();
        config(['fiscal_monitoring.mailbox.max_detail_fetches_per_sync' => 0]);
        [$tenant, $client] = $this->seedTenant();
        $this->seedMessage($tenant, $client, 'Z');

        $runs = app(MailboxDetailEnqueueService::class)->enqueueAfterList($tenant, $client);

        $this->assertSame([], $runs);
        Queue::assertNothingPushed();
    }

    /** @return array{0: Tenant, 1: Client} */
    private function seedTenant(): array
    {
        $tenant = Tenant::factory()->create(['is_active' => true]);
        $client = Client::factory()->for($tenant)->create();

        return [$tenant, $client];
    }

    private function seedMessage(Tenant $tenant, Client $client, string $suffix, bool $unread = true): MailboxMessage
    {
        $externalId = 'EXT-'.$suffix;

        return MailboxMessage::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $tenant->id, (int) $client->id, $externalId),
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
