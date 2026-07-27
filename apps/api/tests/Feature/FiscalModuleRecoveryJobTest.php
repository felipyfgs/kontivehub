<?php

namespace Tests\Feature;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalModuleControlScope;
use App\Jobs\Fiscal\ExecuteFiscalMonitoringRunJob;
use App\Jobs\Fiscal\RecoverFiscalModuleJob;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalMonitoringSchedule;
use App\Models\PlatformMembership;
use App\Models\Tenant;
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
        $tenant = Tenant::factory()->create();
        $controls = app(FiscalModuleControlService::class);
        $controls->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Tenant,
            $tenant,
            true,
            'Pausa',
            $actor,
            false,
        );

        $controls->setRestriction(
            FiscalControlModule::Mailbox,
            FiscalModuleControlScope::Tenant,
            $tenant,
            false,
            'Liberado',
            $actor,
            true,
        );

        Queue::assertPushed(RecoverFiscalModuleJob::class, fn (RecoverFiscalModuleJob $job): bool => $job->moduleKey === 'mailbox' && $job->tenantId === (int) $tenant->id
        );
    }

    public function test_tenant_recovery_is_idempotent_for_enabled_module_schedules(): void
    {
        Queue::fake();
        config(['fiscal.profile' => 'dev', 'fiscal.kill_switch' => false]);
        $tenant = Tenant::factory()->create();
        $client = Client::factory()->forTenant($tenant)->create();
        FiscalMonitoringSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'system_code' => 'CAIXA_POSTAL',
            'service_code' => 'MAILBOX',
            'operation_code' => 'MONITOR',
            'is_enabled' => true,
            'interval_minutes' => 1440,
            'preferred_minute' => 0,
            'next_run_at' => now()->addDay(),
        ]);

        $job = new RecoverFiscalModuleJob('mailbox', (int) $tenant->id, User::factory()->create()->id);
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
