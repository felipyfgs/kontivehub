<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Enums\TenantPermission;

final class ReconcileDeclarationOperationRequest extends DeclarationOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
