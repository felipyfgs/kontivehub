<?php

namespace App\Http\Requests\Tenant;

final class ConfirmAutXmlEnrollmentRequest extends AutXmlRequest
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
