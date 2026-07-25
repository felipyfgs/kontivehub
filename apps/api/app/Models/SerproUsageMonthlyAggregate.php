<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregado mensal recomputável a partir do ledger.
 * scope=TENANT exige office_id; scope=GLOBAL tem office_id null.
 */
#[Fillable([
    'scope',
    'office_id',
    'period_year',
    'period_month',
    'cycle_code',
    'period_kind',
    'system_code',
    'service_code',
    'consumption_class',
    'aggregate_key',
    'entry_count',
    'total_quantity',
    'total_estimated_cost_micros',
    'unknown_class_count',
    'billable_attempt_count',
    'recomputed_at',
])]
class SerproUsageMonthlyAggregate extends Model
{
    public const SCOPE_TENANT = 'TENANT';

    public const SCOPE_GLOBAL = 'GLOBAL';

    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'period_year' => 'integer',
            'period_month' => 'integer',
            'entry_count' => 'integer',
            'total_quantity' => 'integer',
            'total_estimated_cost_micros' => 'integer',
            'unknown_class_count' => 'integer',
            'billable_attempt_count' => 'integer',
            'recomputed_at' => 'immutable_datetime',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(bool $includeOfficeId = true): array
    {
        $data = [
            'scope' => $this->scope,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'system_code' => $this->system_code,
            'service_code' => $this->service_code,
            'consumption_class' => $this->consumption_class?->value,
            'entry_count' => $this->entry_count,
            'total_quantity' => $this->total_quantity,
            'total_estimated_cost_micros' => $this->total_estimated_cost_micros,
            'unknown_class_count' => $this->unknown_class_count,
            'billable_attempt_count' => $this->billable_attempt_count,
            'recomputed_at' => $this->recomputed_at?->toIso8601String(),
        ];

        if ($includeOfficeId) {
            $data['office_id'] = $this->office_id;
        }

        return $data;
    }
}
