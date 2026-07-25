<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'cycle_code',
    'reconciliation_id',
    'office_id',
    'functional_route',
    'http_status',
    'request_tag',
    'system_code',
    'service_code',
    'operation_code',
    'consumption_class',
    'quantity',
    'official_cost_micros',
    'internal_cost_micros',
    'difference_micros',
    'line_status',
    'metadata',
])]
class SerproBillingInvoiceLine extends Model
{
    protected function casts(): array
    {
        return [
            'http_status' => 'integer',
            'quantity' => 'integer',
            'official_cost_micros' => 'integer',
            'internal_cost_micros' => 'integer',
            'difference_micros' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function reconciliation(): BelongsTo
    {
        return $this->belongsTo(SerproUsageReconciliation::class, 'reconciliation_id');
    }
}
