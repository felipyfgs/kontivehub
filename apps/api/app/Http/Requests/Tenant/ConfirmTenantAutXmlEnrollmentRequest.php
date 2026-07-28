<?php

namespace App\Http\Requests\Tenant;

final class ConfirmTenantAutXmlEnrollmentRequest extends TenantAutXmlRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }

    protected function requiresManagementPermission(): bool
    {
        return true;
    }
}
