<?php

namespace App\Http\Resources\Outbound;

use App\Models\OutboundCapacitySnapshot;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class OutboundCapacitySnapshotResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var OutboundCapacitySnapshot $snapshot */
        $snapshot = $this->resource;

        return [
            'competence' => $snapshot->competence,
            'scope' => $snapshot->scope,
            'demand_exchanges' => $snapshot->demand_exchanges,
            'safe_capacity_exchanges' => $snapshot->safe_capacity_exchanges,
            'nominal_capacity_exchanges' => $snapshot->nominal_capacity_exchanges,
            'slack_exchanges' => $snapshot->slack_exchanges,
            'slack_ratio' => $snapshot->slack_ratio,
            'items_total' => $snapshot->items_total,
            'items_planned' => $snapshot->items_planned,
            'items_attention' => $snapshot->items_attention,
            'items_contingency' => $snapshot->items_contingency,
            'items_overdue' => $snapshot->items_overdue,
            'items_captured' => $snapshot->items_captured,
            'items_capacity_at_risk' => $snapshot->items_capacity_at_risk,
            'estimated_completion_at' => $snapshot->estimated_completion_at
                ?->toIso8601String(),
            'target_at' => $snapshot->target_at?->toIso8601String(),
            'due_at' => $snapshot->due_at?->toIso8601String(),
            'at_risk' => $snapshot->at_risk,
            'calculated_at' => $snapshot->calculated_at?->toIso8601String(),
        ];
    }
}
