<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\TenantQuantityLimitData;
use App\DTO\Serpro\UsageLimitsUpdateData;
use App\Enums\SerproEnvironment;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\Rule;

final class UpdateSerproUsageLimitsRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', Rule::enum(SerproEnvironment::class)],
            'cycle_start_day' => ['required', 'integer', 'min:1', 'max:28'],
            'alert_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'global_limit_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'tenant_limits' => ['sometimes', 'array', 'list'],
            'tenant_limits.*.tenant_id' => ['required', 'integer', 'distinct', 'exists:tenants,id'],
            'tenant_limits.*.limit_quantity' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }

    public function toDto(): UsageLimitsUpdateData
    {
        $validated = $this->validated();
        $tenantLimits = array_map(
            static fn (array $limit): TenantQuantityLimitData => new TenantQuantityLimitData(
                tenantId: (int) $limit['tenant_id'],
                limitQuantity: isset($limit['limit_quantity'])
                    ? (int) $limit['limit_quantity']
                    : null,
            ),
            $validated['tenant_limits'] ?? [],
        );

        return new UsageLimitsUpdateData(
            environment: SerproEnvironment::from((string) $validated['environment']),
            cycleStartDay: (int) $validated['cycle_start_day'],
            alertPercent: (int) $validated['alert_percent'],
            globalLimitQuantity: isset($validated['global_limit_quantity'])
                ? (int) $validated['global_limit_quantity']
                : null,
            tenantLimits: $tenantLimits,
        );
    }
}
