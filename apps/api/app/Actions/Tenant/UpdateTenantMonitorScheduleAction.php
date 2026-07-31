<?php

namespace App\Actions\Tenant;

use App\DTO\Tenant\MonitorScheduleData;
use App\DTO\Tenant\MonitorScheduleUpdateData;
use App\Models\TenantMonitorSchedulePolicy;
use App\Services\Audit\AuditLogger;
use App\Services\Tenant\TenantMonitorScheduleCatalog;
use App\Support\CurrentTenant;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class UpdateTenantMonitorScheduleAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private TenantMonitorScheduleCatalog $catalog,
        private AuditLogger $audit,
    ) {}

    public function __invoke(
        string $monitorKey,
        MonitorScheduleUpdateData $data,
    ): MonitorScheduleData {
        $label = $this->catalog->label($monitorKey);
        if ($label === null) {
            throw new NotFoundHttpException('Monitor desconhecido para agendamento.');
        }

        $tenant = $this->currentTenant->tenant();
        $policy = TenantMonitorSchedulePolicy::setCustomDay(
            $tenant->id,
            $monitorKey,
            $data->dayOfMonth,
            $data->actorUserId,
            'America/Sao_Paulo',
        );

        $this->audit->record('tenant.monitor_schedule.update', 'SUCCESS', $policy, [
            'monitor_key' => $monitorKey,
            'day_of_month' => $policy->day_of_month,
            'is_custom' => $policy->is_custom,
        ], $data->actorUserId, $tenant->id);

        return new MonitorScheduleData($policy, $label);
    }
}
