<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\UsageRecomputeData;
use App\Http\Requests\AuthenticatedRequest;

final class RecomputeSerproUsageRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
        ];
    }

    public function toDto(): UsageRecomputeData
    {
        $tenantId = $this->validated('tenant_id');

        return new UsageRecomputeData(
            year: (int) $this->validated('year'),
            month: (int) $this->validated('month'),
            tenantId: is_numeric($tenantId) ? (int) $tenantId : null,
        );
    }
}
