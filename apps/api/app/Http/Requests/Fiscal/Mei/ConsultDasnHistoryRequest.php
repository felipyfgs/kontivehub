<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;

final class ConsultDasnHistoryRequest extends MeiPublicOperationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'calendar_year' => ['sometimes', 'nullable', 'integer', 'min:2009', 'max:2100'],
            'include_full_receipt' => ['sometimes', 'boolean'],
            'confirmed' => ['required', 'accepted'],
            'office_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalSyncTrigger;
    }
}
