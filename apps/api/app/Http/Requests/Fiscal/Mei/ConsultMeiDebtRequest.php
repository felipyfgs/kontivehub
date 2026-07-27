<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;

final class ConsultMeiDebtRequest extends MeiPublicOperationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'calendar_year' => ['required', 'integer', 'min:2009', 'max:2100'],
            'year' => ['prohibited'],
            'confirmed' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalSyncTrigger;
    }
}
