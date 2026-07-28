<?php

namespace App\Http\Resources;

use App\Models\SerproUsageBudget;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SerproUsageBudget */
final class SerproUsageBudgetResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var SerproUsageBudget $budget */
        $budget = $this->resource;

        return [
            'id' => $budget->id,
            'scope' => $budget->scope,
            'tenant_id' => $budget->tenant_id,
            'environment' => $budget->environment,
            'budget_kind' => $budget->budget_kind,
            'limit_micros' => (int) $budget->limit_micros,
            'reserved_micros' => (int) $budget->reserved_micros,
            'consumed_micros' => (int) $budget->consumed_micros,
            'remaining_micros' => $budget->remainingMicros(),
            'cycle_code' => $budget->cycle_code,
            'operation_key' => $budget->operation_key,
            'is_canary' => (bool) $budget->is_canary,
            'is_active' => (bool) $budget->is_active,
            'effective_from' => $budget->effective_from?->toIso8601String(),
            'effective_to' => $budget->effective_to?->toIso8601String(),
        ];
    }
}
