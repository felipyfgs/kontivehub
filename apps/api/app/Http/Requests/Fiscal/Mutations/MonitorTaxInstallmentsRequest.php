<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class MonitorTaxInstallmentsRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_ids' => ['required', 'array', 'min:1', 'max:25'],
            'client_ids.*' => ['required', 'integer', 'distinct'],
            'correlation_id' => ['sometimes', 'string', 'max:48'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function monitorData(): array
    {
        return $this->validated();
    }
}
