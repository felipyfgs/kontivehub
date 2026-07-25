<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'scope',
    'office_id',
    'environment',
    'budget_kind',
    'limit_micros',
    'reserved_micros',
    'consumed_micros',
    'cycle_code',
    'operation_key',
    'is_canary',
    'effective_from',
    'effective_to',
    'is_active',
    'metadata',
])]
class SerproUsageBudget extends Model
{
    protected function casts(): array
    {
        return [
            'is_canary' => 'boolean',
            'is_active' => 'boolean',
            'effective_from' => 'immutable_datetime',
            'effective_to' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function remainingMicros(): int
    {
        return max(0, (int) $this->limit_micros - (int) $this->reserved_micros - (int) $this->consumed_micros);
    }

    public function isPositive(): bool
    {
        return (int) $this->limit_micros > 0 && $this->is_active;
    }
}
