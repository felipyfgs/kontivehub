<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\Tenant;
use App\Services\Integra\Mailbox\MailboxSyncStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxSyncStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_successful_list_advances_reconciled_date(): void
    {
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->for($tenant)->create();
        MailboxClientSyncState::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'pending_event_date' => '2026-07-20',
        ]);
        $service = app(MailboxSyncStateService::class);

        $failed = $service->markListFailed($tenant, $client, 'MAILBOX_LIST_FAILED')->fresh();
        $this->assertSame('2026-07-20', $failed->pending_event_date?->toDateString());
        $this->assertNull($failed->last_reconciled_event_date);

        $succeeded = $service->markListSucceeded($tenant, $client, true);
        $this->assertNull($succeeded->pending_event_date);
        $this->assertSame('2026-07-20', $succeeded->last_reconciled_event_date?->toDateString());
        $this->assertNotNull($succeeded->last_full_reconciliation_at);
    }
}
