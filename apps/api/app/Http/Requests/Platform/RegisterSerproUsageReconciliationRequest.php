<?php

namespace App\Http\Requests\Platform;

use App\DTO\Serpro\UsageReconciliationAdjustmentData;
use App\DTO\Serpro\UsageReconciliationData;
use App\Http\Requests\AuthenticatedRequest;

final class RegisterSerproUsageReconciliationRequest extends AuthenticatedRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'year' => ['required', 'integer', 'min:2020', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'official_total_cost_micros' => ['required', 'integer', 'min:0'],
            'official_reference' => ['sometimes', 'nullable', 'string', 'max:120'],
            'official_source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'difference_cause' => ['sometimes', 'nullable', 'string', 'max:120'],
            'recompute_aggregates' => ['sometimes', 'boolean'],
            'adjustments' => ['sometimes', 'array'],
            'adjustments.*.tenant_id' => ['sometimes', 'nullable', 'integer', 'exists:tenants,id'],
            'adjustments.*.service_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'adjustments.*.consumption_class' => ['sometimes', 'nullable', 'string', 'max:30'],
            'adjustments.*.amount_micros' => ['required_with:adjustments', 'integer'],
            'adjustments.*.reason' => ['required_with:adjustments', 'string', 'max:120'],
            'adjustments.*.notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function toDto(): UsageReconciliationData
    {
        $validated = $this->validated();
        $adjustments = array_map(
            static fn (array $adjustment): UsageReconciliationAdjustmentData => new UsageReconciliationAdjustmentData(
                tenantId: isset($adjustment['tenant_id']) ? (int) $adjustment['tenant_id'] : null,
                serviceCode: isset($adjustment['service_code']) ? (string) $adjustment['service_code'] : null,
                consumptionClass: isset($adjustment['consumption_class'])
                    ? (string) $adjustment['consumption_class']
                    : null,
                amountMicros: (int) $adjustment['amount_micros'],
                reason: (string) $adjustment['reason'],
                notes: isset($adjustment['notes']) ? (string) $adjustment['notes'] : null,
            ),
            $validated['adjustments'] ?? [],
        );

        return new UsageReconciliationData(
            year: (int) $validated['year'],
            month: (int) $validated['month'],
            officialTotalCostMicros: (int) $validated['official_total_cost_micros'],
            officialReference: isset($validated['official_reference'])
                ? (string) $validated['official_reference']
                : null,
            officialSource: isset($validated['official_source']) ? (string) $validated['official_source'] : null,
            notes: isset($validated['notes']) ? (string) $validated['notes'] : null,
            differenceCause: isset($validated['difference_cause'])
                ? (string) $validated['difference_cause']
                : null,
            recomputeAggregates: (bool) ($validated['recompute_aggregates'] ?? true),
            adjustments: $adjustments,
        );
    }
}
