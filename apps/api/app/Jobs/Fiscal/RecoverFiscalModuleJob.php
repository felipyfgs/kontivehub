<?php

namespace App\Jobs\Fiscal;

use App\Enums\FiscalControlModule;
use App\Enums\FiscalOperationClass;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Fiscal\Availability\FiscalModuleAvailabilityService;
use App\Services\FiscalMonitoring\FiscalMonitoringScheduler;
use App\Services\Usage\CommercialMonitorCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Agenda a coleta pós-liberação sem duplicar runs no mesmo minuto lógico. */
final class RecoverFiscalModuleJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly string $moduleKey,
        public readonly ?int $officeId,
        public readonly int $actorUserId,
    ) {
        $this->onQueue((string) config('fiscal_monitoring.job.queue', 'default'));
    }

    public function uniqueId(): string
    {
        return sprintf('fiscal-module-recovery:%s:%s', $this->moduleKey, $this->officeId ?? 'global');
    }

    public function handle(
        FiscalModuleAvailabilityService $availability,
        FiscalMonitoringScheduler $scheduler,
        AuditLogger $audit,
    ): void {
        $module = FiscalControlModule::fromRuntimeKey($this->moduleKey);

        if ($this->officeId === null) {
            Office::query()
                ->where('is_active', true)
                ->select('id')
                ->chunkById(100, function ($offices): void {
                    foreach ($offices as $office) {
                        self::dispatch($this->moduleKey, (int) $office->id, $this->actorUserId);
                    }
                });

            return;
        }

        $office = Office::query()->find($this->officeId);
        if ($office === null || ! $availability->resolve($module, $office, FiscalOperationClass::Read)->allowed) {
            return;
        }

        $now = CarbonImmutable::now()->startOfMinute();
        $dispatched = 0;
        $skipped = 0;

        FiscalMonitoringSchedule::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('is_enabled', true)
            ->orderBy('id')
            ->chunkById(100, function ($schedules) use ($module, $scheduler, $now, &$dispatched, &$skipped): void {
                foreach ($schedules as $schedule) {
                    $monitorKey = CommercialMonitorCatalog::resolveMonitorKey(
                        (string) $schedule->system_code,
                        (string) $schedule->service_code,
                    );
                    if ($monitorKey === null || FiscalControlModule::fromRuntimeKey($monitorKey) !== $module) {
                        continue;
                    }

                    $schedule->forceFill([
                        'next_run_at' => $now,
                        'last_skip_reason' => null,
                    ])->save();
                    $outcome = $scheduler->claimAndEnqueue($schedule, $now);
                    $outcome === 'dispatched' ? $dispatched++ : $skipped++;
                }
            });

        $audit->record('fiscal.module.recovery_scheduled', 'SUCCESS', $office, [
            'module_key' => $module->value,
            'runs_dispatched' => $dispatched,
            'schedules_skipped' => $skipped,
        ], $this->actorUserId, (int) $office->id);
    }
}
