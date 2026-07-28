<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;

final class ShowDeclarationOperationRequest extends DeclarationOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMonitoringView;
    }
}
