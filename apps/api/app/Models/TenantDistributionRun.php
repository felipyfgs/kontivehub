<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id', 'tenant_distribution_cursor_id', 'status', 'trigger', 'triggered_by',
    'from_nsu', 'to_nsu', 'pages_processed', 'documents_persisted', 'documents_quarantined',
    'attempts', 'last_cstat', 'error_code', 'error_message', 'started_at', 'finished_at',
])]
class TenantDistributionRun extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'from_nsu' => 'integer',
            'to_nsu' => 'integer',
            'pages_processed' => 'integer',
            'documents_persisted' => 'integer',
            'documents_quarantined' => 'integer',
            'attempts' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(TenantDistributionCursor::class, 'tenant_distribution_cursor_id');
    }
}
