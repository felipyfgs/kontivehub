<?php

namespace App\Services\Integra\Mailbox;

use App\Jobs\Mailbox\DispatchMailboxMonitoringJob;
use App\Models\MailboxMonitoringSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

final class MailboxMonitoringScheduler
{
    public function dispatchDue(?CarbonImmutable $now = null): int
    {
        if (! (bool) config('fiscal_monitoring.mailbox.economic_monitoring.enabled', false)) {
            return 0;
        }
        $now ??= CarbonImmutable::now('UTC');
        $count = 0;
        $settings = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->where('enabled', true)
            ->where(fn ($query) => $query->whereNull('next_due_at')->orWhere('next_due_at', '<=', $now))
            ->orderBy('office_id')->get();
        foreach ($settings as $setting) {
            $lock = Cache::lock('mailbox-scheduler:'.$setting->office_id, 55);
            if (! $lock->get()) {
                continue;
            }
            try {
                DispatchMailboxMonitoringJob::dispatch((int) $setting->office_id);
                $setting->forceFill(['next_due_at' => $this->nextDue($setting, $now)])->save();
                $count++;
            } finally {
                $lock->release();
            }
        }

        return $count;
    }

    public function nextDue(MailboxMonitoringSetting $setting, CarbonImmutable $now): CarbonImmutable
    {
        $timezone = $setting->timezone ?: 'America/Sao_Paulo';
        [$hour, $minute] = array_map('intval', explode(':', $setting->daily_time ?: '00:30'));
        $localNow = $now->setTimezone($timezone);
        $candidate = $localNow->setTime($hour, $minute);
        if ($candidate->lessThanOrEqualTo($localNow)) {
            $candidate = $candidate->addDay();
        }

        return $candidate->utc();
    }
}
