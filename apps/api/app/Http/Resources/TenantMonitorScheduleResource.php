<?php

namespace App\Http\Resources;

use App\DTO\Tenant\MonitorScheduleData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin MonitorScheduleData */
final class TenantMonitorScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var MonitorScheduleData $schedule */
        $schedule = $this->resource;
        $policy = $schedule->policy;

        return [
            'monitor_key' => $policy->monitor_key,
            'monitor_label' => $schedule->label,
            'day_of_month' => $policy->day_of_month,
            'is_default' => ! $policy->is_custom,
            'timezone' => $policy->timezone ?? 'America/Sao_Paulo',
            'next_run_at' => null,
            'last_run_at' => null,
        ];
    }
}
