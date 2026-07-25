<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;

final class ListDasnHistoryRequest extends MeiPublicOperationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'calendar_year' => ['sometimes', 'nullable', 'integer', 'min:2009', 'max:2100'],
            'office_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMonitoringView;
    }
}
