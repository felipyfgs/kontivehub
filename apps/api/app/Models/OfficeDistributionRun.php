<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'office_distribution_cursor_id', 'status', 'trigger', 'triggered_by',
    'from_nsu', 'to_nsu', 'pages_processed', 'documents_persisted', 'documents_quarantined',
    'attempts', 'last_cstat', 'error_code', 'error_message', 'started_at', 'finished_at',
])]
class OfficeDistributionRun extends Model
{
    use BelongsToOffice;
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
        return $this->belongsTo(OfficeDistributionCursor::class, 'office_distribution_cursor_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_distribution_cursor_id' => $this->office_distribution_cursor_id,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'from_nsu' => $this->from_nsu,
            'to_nsu' => $this->to_nsu,
            'pages_processed' => $this->pages_processed,
            'documents_persisted' => $this->documents_persisted,
            'documents_quarantined' => $this->documents_quarantined,
            'last_cstat' => $this->last_cstat,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'started_at' => $this->started_at?->toIso8601String(),
            'finished_at' => $this->finished_at?->toIso8601String(),
        ];
    }
}
