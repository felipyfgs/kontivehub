<?php

namespace App\Services\FgtsDigital;

use App\Enums\FgtsDigitalGuideType;
use App\Enums\FiscalRunResult;
use App\Enums\OfficeRole;
use App\Jobs\Fiscal\ExecuteFgtsDigitalPolicyJob;
use App\Jobs\Fiscal\ExecuteFgtsDigitalRunJob;
use App\Models\Client;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Office;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class FgtsDigitalScheduleDispatcher
{
    public function __construct(
        private readonly FgtsDigitalReadinessService $readiness,
        private readonly FgtsDigitalPortalService $portal,
    ) {}

    /** @return array{dispatched:int,blocked:int,skipped:int} */
    public function dispatchDue(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $out = ['dispatched' => 0, 'blocked' => 0, 'skipped' => 0];
        if (! (bool) config('fgts_digital.scheduler.enabled', false)
            || (bool) config('fgts_digital.kill_switch', false)) {
            return $out;
        }

        $limit = max(1, min((int) config('fgts_digital.scheduler.max_dispatch_per_tick', 10), 100));
        $schedules = FiscalMonitoringSchedule::query()
            ->withoutGlobalScopes()
            ->where('service_code', 'FGTS_DIGITAL')
            ->where('is_enabled', true)
            ->where(fn ($query) => $query->whereNull('next_run_at')->orWhere('next_run_at', '<=', $now))
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($schedules as $schedule) {
            $lock = Cache::lock('fgts-digital:schedule:'.$schedule->office_id.':'.$schedule->id, 55);
            if (! $lock->get()) {
                $out['skipped']++;

                continue;
            }
            try {
                $result = $this->dispatchSchedule($schedule, $now);
                $out[$result]++;
            } finally {
                $lock->release();
            }
        }

        return $out;
    }

    /** @return 'dispatched'|'blocked'|'skipped' */
    private function dispatchSchedule(FiscalMonitoringSchedule $schedule, CarbonImmutable $now): string
    {
        $office = Office::query()->find($schedule->office_id);
        $client = Client::query()->withoutGlobalScopes()
            ->where('office_id', $schedule->office_id)
            ->whereKey($schedule->client_id)
            ->first();
        if ($office === null || $client === null) {
            return $this->finish($schedule, $now, 'blocked', 'FGTS_DIGITAL_TENANT_NOT_FOUND');
        }

        $ready = $this->readiness->check($office, $client);
        $operation = strtoupper((string) $schedule->operation_code);
        if ($operation === 'QUERY_GUIDES') {
            if (! $ready['ready_for_read']) {
                return $this->finish($schedule, $now, 'blocked', (string) ($ready['blockers'][0]['code'] ?? 'FGTS_DIGITAL_NOT_READY'));
            }
            $run = $this->portal->createQueryRun($office, $client, null, $this->queryParameters($schedule));
            ExecuteFgtsDigitalRunJob::dispatch((int) $office->id, (int) $run->id);

            return $this->finish($schedule, $now, 'dispatched', null);
        }

        if ($operation !== 'EMIT_GUIDE') {
            return $this->finish($schedule, $now, 'blocked', 'FGTS_DIGITAL_SCHEDULE_OPERATION_INVALID');
        }
        if (! (bool) config('fgts_digital.scheduler.emissions_enabled', false)) {
            return $this->finish($schedule, $now, 'blocked', 'FGTS_DIGITAL_SCHEDULED_EMISSIONS_DISABLED');
        }
        if (! $ready['ready_for_mutation']) {
            return $this->finish($schedule, $now, 'blocked', (string) ($ready['blockers'][0]['code'] ?? 'FGTS_DIGITAL_MUTATIONS_DISABLED'));
        }
        if (($blocker = $this->policyBlocker($schedule, $office, $now)) !== null) {
            return $this->finish($schedule, $now, 'blocked', $blocker);
        }

        ExecuteFgtsDigitalPolicyJob::dispatch((int) $office->id, (int) $schedule->id);

        return $this->finish($schedule, $now, 'dispatched', null);
    }

    public function policyBlocker(
        FiscalMonitoringSchedule $schedule,
        Office $office,
        ?CarbonImmutable $now = null,
    ): ?string {
        $now ??= CarbonImmutable::now();
        $policy = $schedule->metadata['fgts_digital_policy'] ?? null;
        if (! is_array($policy) || ($policy['enabled'] ?? false) !== true) {
            return 'FGTS_DIGITAL_SCHEDULE_POLICY_MISSING';
        }
        $validUntil = isset($policy['valid_until']) ? CarbonImmutable::parse((string) $policy['valid_until']) : null;
        if ($validUntil === null || $validUntil->lessThanOrEqualTo($now)) {
            return 'FGTS_DIGITAL_SCHEDULE_POLICY_EXPIRED';
        }
        $user = User::query()->find((int) ($policy['authorized_by_user_id'] ?? 0));
        if ($user === null || ! $user->is_active || $user->roleIn($office) !== OfficeRole::Admin) {
            return 'FGTS_DIGITAL_SCHEDULE_POLICY_AUTHORIZER_INVALID';
        }
        $parameters = $this->policyParameters($schedule);
        $guideType = FgtsDigitalGuideType::tryFrom((string) ($parameters['guide_type'] ?? ''));
        $allowedTypes = array_map('strval', (array) ($policy['guide_types'] ?? []));
        if ($guideType === null || ! in_array($guideType->value, $allowedTypes, true)) {
            return 'FGTS_DIGITAL_SCHEDULE_POLICY_GUIDE_TYPE_BLOCKED';
        }
        $amount = (int) ($parameters['amount_cents'] ?? 0);
        $policyLimit = (int) ($policy['max_amount_cents'] ?? 0);
        $globalLimit = (int) config('fgts_digital.scheduler.max_amount_cents', 0);
        if ($amount <= 0 || $policyLimit <= 0 || $globalLimit <= 0
            || $amount > min($policyLimit, $globalLimit)) {
            return 'FGTS_DIGITAL_SCHEDULE_POLICY_AMOUNT_BLOCKED';
        }
        if (array_intersect(['pix', 'pix_code', 'qr_code', 'payment'], array_keys($parameters)) !== []) {
            return 'FGTS_DIGITAL_PAYMENT_NOT_ALLOWED';
        }

        return null;
    }

    /** @return array<string, mixed> */
    public function policyParameters(FiscalMonitoringSchedule $schedule): array
    {
        $parameters = $schedule->metadata['fgts_digital_parameters'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }

    /** @return array<string, mixed> */
    private function queryParameters(FiscalMonitoringSchedule $schedule): array
    {
        $parameters = $schedule->metadata['fgts_digital_query'] ?? [];

        return is_array($parameters) ? $parameters : [];
    }

    /** @param 'dispatched'|'blocked'|'skipped' $result @return 'dispatched'|'blocked'|'skipped' */
    private function finish(
        FiscalMonitoringSchedule $schedule,
        CarbonImmutable $now,
        string $result,
        ?string $reason,
    ): string {
        $interval = max(1, (int) $schedule->interval_minutes);
        $schedule->forceFill([
            'last_run_at' => $now,
            'last_result' => match ($result) {
                'dispatched' => FiscalRunResult::Requeued,
                'blocked' => FiscalRunResult::Blocked,
                default => FiscalRunResult::Skipped,
            },
            'last_skip_reason' => $reason,
            'next_run_at' => $now->addMinutes($interval),
        ])->save();

        return $result;
    }
}
