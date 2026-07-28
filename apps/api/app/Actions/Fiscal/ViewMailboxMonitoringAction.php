<?php

namespace App\Actions\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxMonitoringOverviewData;
use App\Models\MailboxClientSyncState;
use App\Models\MailboxMonitoringSetting;
use App\Models\SerproEventosRun;
use App\Models\Tenant;

final class ViewMailboxMonitoringAction
{
    public function handle(Tenant $tenant): MailboxMonitoringOverviewData
    {
        $setting = MailboxMonitoringSetting::query()
            ->withoutGlobalScopes()
            ->firstOrNew(['tenant_id' => $tenant->id]);
        $states = MailboxClientSyncState::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->get();
        $lastFree = SerproEventosRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('evento', 'E0601')
            ->whereNotNull('remote_result_received_at')
            ->max('remote_result_received_at');

        return new MailboxMonitoringOverviewData(
            enabled: (bool) $setting->enabled,
            runtimeEnabled: (bool) $setting->enabled
                && (bool) config(
                    'fiscal_monitoring.mailbox.economic_monitoring.enabled',
                    false,
                ),
            mode: $setting->mode->value,
            dailyTime: (string) $setting->daily_time,
            timezone: (string) $setting->timezone,
            reconciliationDays: (int) $setting->reconciliation_days,
            autoDetailLimit: (int) $setting->auto_detail_limit,
            monthlyBudgetMicros: $setting->monthly_budget_micros !== null
                ? (int) $setting->monthly_budget_micros
                : null,
            coverage: [
                'initialized_clients' => $states
                    ->whereNotNull('bootstrap_completed_at')
                    ->count(),
                'pending_clients' => $states
                    ->whereNotNull('pending_event_date')
                    ->count(),
                'blocked_clients' => $states
                    ->where('authorization_status', 'DENIED')
                    ->count(),
                'failed_clients' => $states
                    ->whereNotNull('last_error_code')
                    ->count(),
            ],
            lastFreeCheckAt: $lastFree,
            lastPaidCheckAt: $states->max('last_list_at')?->toIso8601String(),
            lastFullReconciliationAt: $states
                ->max('last_full_reconciliation_at')
                ?->toIso8601String(),
            lastDispatchedAt: $setting->last_dispatched_at?->toIso8601String(),
            nextDueAt: $setting->next_due_at?->toIso8601String(),
        );
    }
}
