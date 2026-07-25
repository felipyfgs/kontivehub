<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'office_id', 'competence', 'scope', 'root_cnpj', 'model',
    'demand_exchanges', 'safe_capacity_exchanges', 'nominal_capacity_exchanges',
    'slack_exchanges', 'slack_ratio', 'items_total', 'items_planned', 'items_attention',
    'items_contingency', 'items_overdue', 'items_captured', 'items_capacity_at_risk',
    'estimated_completion_at', 'target_at', 'due_at', 'at_risk', 'metrics', 'calculated_at',
])]
class OutboundCapacitySnapshot extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'demand_exchanges' => 'integer',
            'safe_capacity_exchanges' => 'integer',
            'nominal_capacity_exchanges' => 'integer',
            'slack_exchanges' => 'integer',
            'slack_ratio' => 'float',
            'items_total' => 'integer',
            'items_planned' => 'integer',
            'items_attention' => 'integer',
            'items_contingency' => 'integer',
            'items_overdue' => 'integer',
            'items_captured' => 'integer',
            'items_capacity_at_risk' => 'integer',
            'estimated_completion_at' => 'immutable_datetime',
            'target_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'at_risk' => 'boolean',
            'metrics' => 'array',
            'calculated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'competence' => $this->competence,
            'scope' => $this->scope,
            'demand_exchanges' => $this->demand_exchanges,
            'safe_capacity_exchanges' => $this->safe_capacity_exchanges,
            'nominal_capacity_exchanges' => $this->nominal_capacity_exchanges,
            'slack_exchanges' => $this->slack_exchanges,
            'slack_ratio' => $this->slack_ratio,
            'items_total' => $this->items_total,
            'items_planned' => $this->items_planned,
            'items_attention' => $this->items_attention,
            'items_contingency' => $this->items_contingency,
            'items_overdue' => $this->items_overdue,
            'items_captured' => $this->items_captured,
            'items_capacity_at_risk' => $this->items_capacity_at_risk,
            'estimated_completion_at' => $this->estimated_completion_at?->toIso8601String(),
            'target_at' => $this->target_at?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'at_risk' => $this->at_risk,
            'calculated_at' => $this->calculated_at?->toIso8601String(),
        ];
    }
}
