<?php

namespace App\Services\Work;

use App\Domain\Work\DueDateCalculator;
use App\DTO\Work\ProcessViewData;
use App\Models\WorkProcess;
use App\Support\CurrentTenant;
use App\Support\Work\TenantTimezone;

final readonly class ProcessViewBuilder
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private MonitoringContextRegistry $monitoringContexts,
        private DueDateCalculator $dates = new DueDateCalculator,
    ) {}

    public function fromLoaded(
        WorkProcess $process,
        bool $detailed = false,
        bool $includeTasks = true,
    ): ProcessViewData {
        return new ProcessViewData(
            process: $process,
            today: $this->dates->todayInTenant(
                TenantTimezone::for($this->currentTenant->tenant()),
            ),
            detailed: $detailed,
            includeTasks: $includeTasks,
            monitoringContext: $this->monitoringContexts->forClient(
                $process->monitoring_module_key,
                (int) $process->client_id,
            ),
        );
    }

    public function detailed(WorkProcess $process): ProcessViewData
    {
        $process->load([
            'client.establishments',
            'tasks.evidences',
            'tasks.assigneeMembership.user',
            'tasks.department',
            'department',
            'assigneeMembership.user',
            'comments',
        ]);

        return $this->fromLoaded($process, detailed: true);
    }
}
