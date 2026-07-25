<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'kind',
    'severity',
    'environment',
    'office_id',
    'cycle_code',
    'sanitized_summary',
    'expected_micros',
    'observed_micros',
    'metadata',
    'opened_at',
    'resolved_at',
])]
class SerproUsageIncident extends Model
{
    public const KIND_RECONCILIATION_DIVERGENCE = 'RECONCILIATION_DIVERGENCE';

    public const KIND_PRICE_UNKNOWN = 'PRICE_UNKNOWN';

    public const KIND_BUDGET_EXCEEDED = 'BUDGET_EXCEEDED';

    public const KIND_SHADOW_SEGREGATION = 'SHADOW_SEGREGATION';

    public const SEVERITY_OPEN = 'OPEN';

    public const SEVERITY_RESOLVED = 'RESOLVED';

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'opened_at' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
        ];
    }
}
