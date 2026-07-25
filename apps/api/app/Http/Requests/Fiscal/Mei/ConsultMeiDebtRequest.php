<?php

namespace App\Http\Requests\Fiscal\Mei;

use App\Enums\TenantPermission;

final class ConsultMeiDebtRequest extends MeiPublicOperationRequest
{
    protected function prepareForValidation(): void
    {
        if (! $this->has('calendar_year') && $this->has('year')) {
            $this->merge(['calendar_year' => $this->input('year')]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:100'],
            'client_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            'calendar_year' => ['required', 'integer', 'min:2009', 'max:2100'],
            'year' => ['sometimes', 'integer', 'min:2009', 'max:2100'],
            'confirmed' => ['required', 'accepted'],
            'office_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalSyncTrigger;
    }
}
