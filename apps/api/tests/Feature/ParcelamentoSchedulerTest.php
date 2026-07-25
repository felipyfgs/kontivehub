<?php

namespace Tests\Feature;

use App\Enums\FiscalTrigger;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\MonitorCommercialLedgerEntry;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalMonitoringScheduler;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use ReflectionMethod;
use Tests\TestCase;

class ParcelamentoSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_commercial_scheduler_expands_installments_into_eight_idempotent_runs(): void
    {
        Queue::fake();
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $entry = MonitorCommercialLedgerEntry::factory()
            ->forClient($client)
            ->scheduled()
            ->monitor('installments')
            ->create();

        $method = new ReflectionMethod(FiscalMonitoringScheduler::class, 'enqueueCommercialScheduledRun');
        $scheduler = app(FiscalMonitoringScheduler::class);
        $now = CarbonImmutable::parse('2026-07-21 12:00:00', 'America/Sao_Paulo');

        $this->assertSame('dispatched', $method->invoke(
            $scheduler,
            $office->id,
            $client->id,
            'installments',
            $entry,
            $now,
        ));
        $this->assertSame('skipped', $method->invoke(
            $scheduler,
            $office->id,
            $client->id,
            'installments',
            $entry->fresh(),
            $now,
        ));

        $runs = FiscalMonitoringRun::query()->withoutGlobalScopes()->get();
        $this->assertCount(8, $runs);
        $this->assertTrue($runs->every(fn (FiscalMonitoringRun $run): bool => $run->system_code === 'INTEGRA_PARCELAMENTO'
            && $run->operation_code === 'MONITOR'
            && $run->trigger === FiscalTrigger::Scheduled
        ));
        $this->assertFalse($runs->contains('service_code', 'INSTALLMENTS'));
        $this->assertFalse($runs->contains('service_code', 'PARCELAMENTO'));
        $this->assertFalse($runs->contains('service_code', 'PARC-PAEX'));
        $this->assertFalse($runs->contains('service_code', 'PARC-SIPADE'));
        $this->assertCount(8, $entry->fresh()->metadata['fiscal_monitoring_run_ids']);
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 8);
    }
}
