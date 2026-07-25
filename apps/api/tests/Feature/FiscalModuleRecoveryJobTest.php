<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
use App\Models\PlatformMembership;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\Fiscal\Availability\FiscalModuleControlService;
use App\Services\FiscalMonitoring\FiscalMonitoringScheduler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class FiscalModuleRecoveryJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_release_dispatches_recovery_job(): void
    {
        Queue::fake();
        $actor = User::factory()->create();
        PlatformMembership::factory()->forUser($actor)->create();
        $office = Office::factory()->create();
        $controls = app(FiscalModuleControlService::class);
        $controls->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Office,
            $office,
            true,
            'Pausa',
            $actor,
            false,
        );

        $controls->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Office,
            $office,
            false,
            'Liberado',
            $actor,
            true,
        );

        Queue::assertPushed(RecoverFiscalModuleJob::class, fn (RecoverFiscalModuleJob $job): bool => $job->moduleKey === 'caixa_postal' && $job->officeId === (int) $office->id
        );
    }

    public function test_office_recovery_is_idempotent_for_enabled_module_schedules(): void
    {
        Queue::fake();
        config(['fiscal.profile' => 'dev', 'fiscal.kill_switch' => false]);
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        FiscalMonitoringSchedule::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'system_code' => 'CAIXA_POSTAL',
            'service_code' => 'MAILBOX',
            'operation_code' => 'MONITOR',
            'is_enabled' => true,
            'interval_minutes' => 1440,
            'preferred_minute' => 0,
            'next_run_at' => now()->addDay(),
        ]);

        $job = new RecoverFiscalModuleJob('caixa_postal', (int) $office->id, User::factory()->create()->id);
        $job->handle(
            app(FiscalModuleAvailabilityService::class),
            app(FiscalMonitoringScheduler::class),
            app(AuditLogger::class),
        );
        $job->handle(
            app(FiscalModuleAvailabilityService::class),
            app(FiscalMonitoringScheduler::class),
            app(AuditLogger::class),
        );

        $this->assertSame(1, FiscalMonitoringRun::query()->withoutGlobalScopes()->count());
        Queue::assertPushed(ExecuteFiscalMonitoringRunJob::class, 1);
    }
}
