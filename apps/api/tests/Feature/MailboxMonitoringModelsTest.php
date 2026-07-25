<?php

namespace Tests\Feature;

use App\Enums\MailboxEventItemClassification;
use App\Enums\MailboxEventProcessingStatus;
use App\Enums\MailboxMonitoringMode;
use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Models\SerproEventosRun;
use App\Models\SerproEventosRunItem;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxMonitoringModelsTest extends TestCase
{
    use RefreshDatabase;

    public function test_casts_defaults_and_unique_office_setting(): void
    {
        $office = Office::factory()->create();
        $setting = MailboxMonitoringSetting::query()->create(['office_id' => $office->id]);

        $this->assertFalse($setting->enabled);
        $this->assertSame(MailboxMonitoringMode::Economic, $setting->mode);
        $this->assertSame(30, $setting->reconciliation_days);
        $this->assertSame(0, $setting->auto_detail_limit);

        $this->expectException(QueryException::class);
        MailboxMonitoringSetting::query()->create(['office_id' => $office->id]);
    }

    public function test_sync_and_event_items_are_office_scoped_and_unique(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->for($office)->create();
        $state = MailboxClientSyncState::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'pending_event_date' => '2026-07-20',
        ]);
        $this->assertSame('2026-07-20', $state->pending_event_date?->toDateString());

        $run = SerproEventosRun::query()->create([
            'office_id' => $office->id,
            'environment' => SerproEnvironment::Trial,
            'person_type' => 'PJ',
            'phase' => SerproEventosRun::PHASE_CONSUMED,
            'status' => SerproEventosRun::STATUS_RUNNING,
            'evento' => 'E0601',
        ]);
        $item = SerproEventosRunItem::query()->create([
            'serpro_eventos_run_id' => $run->id,
            'office_id' => $office->id,
            'client_id' => $client->id,
            'ni_fingerprint' => hash('sha256', 'ni'),
            'classification' => MailboxEventItemClassification::EventDate,
            'event_date' => '2026-07-20',
            'processing_status' => MailboxEventProcessingStatus::Pending,
        ]);

        $this->assertSame(MailboxEventItemClassification::EventDate, $item->classification);
        $this->assertSame($office->id, $item->office_id);
    }
}
