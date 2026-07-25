<?php

namespace Tests\Feature;

use App\Jobs\Mailbox\DispatchMailboxMonitoringJob;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Services\Integra\Mailbox\MailboxMonitoringScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxMonitoringSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_due_setting_once_and_calculates_sao_paulo_next_due(): void
    {
        Queue::fake();
        config(['fiscal_monitoring.mailbox.economic_monitoring.enabled' => true]);
        $office = Office::factory()->create();
        $setting = MailboxMonitoringSetting::query()->create([
            'office_id' => $office->id,
            'enabled' => true,
            'daily_time' => '00:30',
            'timezone' => 'America/Sao_Paulo',
            'next_due_at' => null,
        ]);
        $now = CarbonImmutable::parse('2026-07-21T04:00:00Z');
        $scheduler = app(MailboxMonitoringScheduler::class);

        $this->assertSame(1, $scheduler->dispatchDue($now));
        $this->assertSame(0, $scheduler->dispatchDue($now));
        $this->assertSame('2026-07-22T03:30:00+00:00', $setting->fresh()->next_due_at?->toIso8601String());
        Queue::assertPushed(DispatchMailboxMonitoringJob::class, 1);
    }
}
