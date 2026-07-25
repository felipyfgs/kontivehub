<?php

namespace App\Models;

use App\Enums\SerproConsumptionClass;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ajuste/diferença de conciliação — separado do ledger original.
 */
#[Fillable([
    'reconciliation_id',
    'office_id',
    'service_code',
    'consumption_class',
    'amount_micros',
    'reason',
    'notes',
    'created_at',
])]
class SerproUsageReconciliationAdjustment extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'consumption_class' => SerproConsumptionClass::class,
            'amount_micros' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(SerproUsageReconciliation::class, 'reconciliation_id');
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPlatformArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'service_code' => $this->service_code,
            'consumption_class' => $this->consumption_class?->value,
            'amount_micros' => $this->amount_micros,
            'reason' => $this->reason,
            'notes' => $this->notes,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
