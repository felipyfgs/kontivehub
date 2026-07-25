<?php

namespace App\Jobs\Fiscal;

use App\Enums\FgtsDigitalGuideType;
use App\Enums\FiscalRunResult;
use App\Models\Client;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
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
        public readonly int $officeId,
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
            ->where('office_id', $this->officeId)
            ->whereKey($this->scheduleId)
            ->first();
        $office = Office::query()->find($this->officeId);
        $client = $schedule === null ? null : Client::query()->withoutGlobalScopes()
            ->where('office_id', $this->officeId)
            ->whereKey($schedule->client_id)
            ->first();
        if ($schedule === null || $office === null || $client === null
            || ($blocker = $dispatcher->policyBlocker($schedule, $office)) !== null) {
            $this->block($schedule, $blocker ?? 'FGTS_DIGITAL_TENANT_NOT_FOUND');

            return;
        }
        $ready = $readiness->check($office, $client);
        if (! $ready['ready_for_mutation']) {
            $this->block($schedule, (string) ($ready['blockers'][0]['code'] ?? 'FGTS_DIGITAL_NOT_READY'));

            return;
        }

        $policy = $schedule->metadata['fgts_digital_policy'];
        $user = User::query()->findOrFail((int) $policy['authorized_by_user_id']);
        $parameters = $dispatcher->policyParameters($schedule);
        $guideType = FgtsDigitalGuideType::from((string) $parameters['guide_type']);
        try {
            $preview = $portal->preview($office, $client, $user, $guideType, $parameters);
            if ($preview['preview_token'] === null) {
                $this->block($schedule, (string) ($preview['run']->code ?? 'FGTS_DIGITAL_PREVIEW_BLOCKED'));

                return;
            }
            $authorized = $portal->authorizeEmission(
                $office,
                $preview['run'],
                $user,
                $preview['preview_token'],
                (string) $preview['run']->confirmation_phrase,
            );
            if (! $authorized['reused']) {
                ExecuteFgtsDigitalRunJob::dispatch((int) $office->id, (int) $authorized['run']->id);
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
            'office_id' => $this->officeId,
            'schedule_id' => $this->scheduleId,
            'code' => $code,
        ]);
    }
}
