<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Tenant\TenantMonitorScheduleUpdateData;

final class UpdateTenantMonitorScheduleRequest extends TenantSettingsMutationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'day_of_month' => ['required', 'integer', 'min:1', 'max:28'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function toDto(): TenantMonitorScheduleUpdateData
    {
        return new TenantMonitorScheduleUpdateData(
            dayOfMonth: (int) $this->validated('day_of_month'),
            actorUserId: $this->actor()->id,
        );
    }
}
