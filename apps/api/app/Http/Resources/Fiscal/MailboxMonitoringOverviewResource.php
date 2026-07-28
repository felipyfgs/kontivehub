<?php

namespace App\Http\Resources\Fiscal;

use App\DTO\Fiscal\Monitoring\MailboxMonitoringOverviewData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MailboxMonitoringOverviewData */
final class MailboxMonitoringOverviewResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MailboxMonitoringOverviewData $data */
        $data = $this->resource;

        return [
            'enabled' => $data->enabled,
            'runtime_enabled' => $data->runtimeEnabled,
            'mode' => $data->mode,
            'daily_time' => $data->dailyTime,
            'timezone' => $data->timezone,
            'reconciliation_days' => $data->reconciliationDays,
            'auto_detail_limit' => $data->autoDetailLimit,
            'monthly_budget_micros' => $data->monthlyBudgetMicros,
            'coverage' => $data->coverage,
            'last_free_check_at' => $data->lastFreeCheckAt,
            'last_paid_check_at' => $data->lastPaidCheckAt,
            'last_full_reconciliation_at' => $data
                ->lastFullReconciliationAt,
            'last_dispatched_at' => $data->lastDispatchedAt,
            'next_due_at' => $data->nextDueAt,
            'indicator_note' => 'O indicador gratuito é diagnóstico; zero não comprova caixa vazia.',
        ];
    }
}
