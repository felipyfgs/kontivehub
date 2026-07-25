<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\Office;
use App\Services\Integra\Mailbox\MailboxSyncStateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxSyncStateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_successful_list_advances_reconciled_date(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        MailboxClientSyncState::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'pending_event_date' => '2026-07-20',
        ]);
        $service = app(MailboxSyncStateService::class);

        $failed = $service->markListFailed($office, $client, 'MAILBOX_LIST_FAILED')->fresh();
        $this->assertSame('2026-07-20', $failed->pending_event_date?->toDateString());
        $this->assertNull($failed->last_reconciled_event_date);

        $succeeded = $service->markListSucceeded($office, $client, true);
        $this->assertNull($succeeded->pending_event_date);
        $this->assertSame('2026-07-20', $succeeded->last_reconciled_event_date?->toDateString());
        $this->assertNotNull($succeeded->last_full_reconciliation_at);
    }
}
