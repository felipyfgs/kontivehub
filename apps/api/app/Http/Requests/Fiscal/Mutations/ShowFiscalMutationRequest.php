<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;

final class ShowFiscalMutationRequest extends FiscalMutationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
        ];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMonitoringView;
    }
}
