<?php

namespace App\Http\Requests\Tenant;

use App\DTO\Platform\TenantSelectionData;
use App\Http\Requests\AuthenticatedRequest;

final class SwitchRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function toDto(): TenantSelectionData
    {
        return new TenantSelectionData(
            tenantId: (int) $this->validated('tenant_id'),
        );
    }
}
