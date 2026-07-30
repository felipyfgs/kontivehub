<?php

namespace Tests\Feature;

use App\Jobs\Mailbox\DispatchMailboxMonitoringJob;
use App\Models\MailboxMonitoringSetting;
use App\Models\Tenant;
use App\Services\Integra\Mailbox\MailboxMonitoringScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MailboxMonitoringSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatches_due_setting_once_and_calculates_sao_paulo_next_due(): void
    {
        Queue::fake();
        config(['fiscal_monitoring.mailbox.economic_monitoring.enabled' => true]);
        $tenant = Tenant::factory()->create();
        $setting = MailboxMonitoringSetting::query()->create([
            'tenant_id' => $tenant->id,
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

    public function test_dispatches_all_due_settings_across_multiple_chunks(): void
    {
        Queue::fake();
        config([
            'fiscal_monitoring.mailbox.economic_monitoring.enabled' => true,
            'fiscal_monitoring.mailbox.economic_monitoring.scheduler_chunk_size' => 2,
        ]);
        Tenant::factory()->count(5)->create()->each(
            fn (Tenant $tenant) => MailboxMonitoringSetting::query()->create([
                'tenant_id' => $tenant->id,
                'enabled' => true,
                'daily_time' => '00:30',
                'timezone' => 'America/Sao_Paulo',
                'next_due_at' => null,
            ]),
        );

        $count = app(MailboxMonitoringScheduler::class)->dispatchDue(
            CarbonImmutable::parse('2026-07-21T04:00:00Z'),
        );

        $this->assertSame(5, $count);
        Queue::assertPushed(DispatchMailboxMonitoringJob::class, 5);
        $this->assertSame(0, MailboxMonitoringSetting::query()->whereNull('next_due_at')->count());
    }

    public function test_empty_dataset_is_a_no_op(): void
    {
        Queue::fake();
        config(['fiscal_monitoring.mailbox.economic_monitoring.enabled' => true]);

        $this->assertSame(
            0,
            app(MailboxMonitoringScheduler::class)->dispatchDue(
                CarbonImmutable::parse('2026-07-21T04:00:00Z'),
            ),
        );
        Queue::assertNothingPushed();
    }

    public function test_locked_setting_remains_due_and_is_processed_on_reentry(): void
    {
        Queue::fake();
        config([
            'fiscal_monitoring.mailbox.economic_monitoring.enabled' => true,
            'fiscal_monitoring.mailbox.economic_monitoring.scheduler_chunk_size' => 1,
        ]);
        $blockedTenant = Tenant::factory()->create();
        $availableTenant = Tenant::factory()->create();
        foreach ([$blockedTenant, $availableTenant] as $tenant) {
            MailboxMonitoringSetting::query()->create([
                'tenant_id' => $tenant->id,
                'enabled' => true,
                'daily_time' => '00:30',
                'timezone' => 'America/Sao_Paulo',
                'next_due_at' => null,
            ]);
        }
        $heldLock = Cache::lock('mailbox-scheduler:'.$blockedTenant->id, 55);
        $this->assertTrue($heldLock->get());
        $scheduler = app(MailboxMonitoringScheduler::class);
        $now = CarbonImmutable::parse('2026-07-21T04:00:00Z');

        try {
            $this->assertSame(1, $scheduler->dispatchDue($now));
            $this->assertNull(
                MailboxMonitoringSetting::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $blockedTenant->id)
                    ->firstOrFail()
                    ->next_due_at,
            );
        } finally {
            $heldLock->release();
        }

        $this->assertSame(1, $scheduler->dispatchDue($now));
        Queue::assertPushed(DispatchMailboxMonitoringJob::class, 2);
    }
}
