<?php

namespace App\Models;

use App\Enums\SerproReconciliationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Conciliação fatura oficial SERPRO × agregados internos.
 * Não reescreve o ledger original.
 */
#[Fillable([
    'period_year',
    'period_month',
    'official_reference',
    'official_source',
    'official_total_cost_micros',
    'internal_total_estimated_cost_micros',
    'difference_micros',
    'status',
    'difference_cause',
    'notes',
    'imported_by_user_id',
    'imported_at',
])]
class SerproUsageReconciliation extends Model
{
    protected function casts(): array
    {
        return [
            'period_year' => 'integer',
            'period_month' => 'integer',
            'official_total_cost_micros' => 'integer',
            'internal_total_estimated_cost_micros' => 'integer',
            'difference_micros' => 'integer',
            'status' => SerproReconciliationStatus::class,
            'imported_at' => 'immutable_datetime',
        ];
    }

    public function adjustments(): HasMany
    {
        return $this->hasMany(SerproUsageReconciliationAdjustment::class, 'reconciliation_id');
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPlatformArray(): array
    {
        return [
            'id' => $this->id,
            'period_year' => $this->period_year,
            'period_month' => $this->period_month,
            'official_reference' => $this->official_reference,
            'official_source' => $this->official_source,
            'official_total_cost_micros' => $this->official_total_cost_micros,
            'internal_total_estimated_cost_micros' => $this->internal_total_estimated_cost_micros,
            'difference_micros' => $this->difference_micros,
            'status' => $this->status->value,
            'difference_cause' => $this->difference_cause,
            'notes' => $this->notes,
            'imported_at' => $this->imported_at?->toIso8601String(),
            'adjustments' => $this->relationLoaded('adjustments')
                ? $this->adjustments->map(fn (SerproUsageReconciliationAdjustment $a) => $a->toPlatformArray())->all()
                : null,
        ];
    }
}
