<?php

namespace App\Jobs\Fiscal;

use App\Enums\FgtsDigitalGuideType;
use App\Enums\FiscalRunResult;
use App\Models\Client;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalPortalService;
use App\Services\FgtsDigital\FgtsDigitalReadinessService;
use App\Services\FgtsDigital\FgtsDigitalScheduleDispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

final class ExecuteFgtsDigitalPolicyJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $scheduleId,
    ) {
        $this->onQueue((string) config('fgts_digital.queue', 'default'));
    }

    public function handle(
        FgtsDigitalScheduleDispatcher $dispatcher,
        FgtsDigitalReadinessService $readiness,
        FgtsDigitalPortalService $portal,
    ): void {
        $schedule = FiscalMonitoringSchedule::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->scheduleId)
            ->first();
        $tenant = Tenant::query()->find($this->tenantId);
        $client = $schedule === null ? null : Client::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($schedule->client_id)
            ->first();
        if ($schedule === null || $tenant === null || $client === null
            || ($blocker = $dispatcher->policyBlocker($schedule, $tenant)) !== null) {
            $this->block($schedule, $blocker ?? 'FGTS_DIGITAL_TENANT_NOT_FOUND');

            return;
        }
        $ready = $readiness->check($tenant, $client);
        if (! $ready['ready_for_mutation']) {
            $this->block($schedule, (string) ($ready['blockers'][0]['code'] ?? 'FGTS_DIGITAL_NOT_READY'));

            return;
        }

        $policy = $schedule->metadata['fgts_digital_policy'];
        $user = User::query()->findOrFail((int) $policy['authorized_by_user_id']);
        $parameters = $dispatcher->policyParameters($schedule);
        $guideType = FgtsDigitalGuideType::from((string) $parameters['guide_type']);
        try {
            $preview = $portal->preview($tenant, $client, $user, $guideType, $parameters);
            if ($preview['preview_token'] === null) {
                $this->block($schedule, (string) ($preview['run']->code ?? 'FGTS_DIGITAL_PREVIEW_BLOCKED'));

                return;
            }
            $authorized = $portal->authorizeEmission(
                $tenant,
                $preview['run'],
                $user,
                $preview['preview_token'],
                (string) $preview['run']->confirmation_phrase,
            );
            if (! $authorized['reused']) {
                ExecuteFgtsDigitalRunJob::dispatch((int) $tenant->id, (int) $authorized['run']->id);
            }
        } catch (FgtsDigitalException $e) {
            $this->block($schedule, $e->codeKey);
        }
    }

    private function block(?FiscalMonitoringSchedule $schedule, string $code): void
    {
        $schedule?->forceFill([
            'last_result' => FiscalRunResult::Blocked,
            'last_skip_reason' => $code,
        ])->save();
        Log::notice('fgts_digital.policy_blocked', [
            'tenant_id' => $this->tenantId,
            'schedule_id' => $this->scheduleId,
            'code' => $code,
        ]);
    }
}
