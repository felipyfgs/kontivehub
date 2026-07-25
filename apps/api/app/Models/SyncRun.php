<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'sync_cursor_id', 'status', 'trigger', 'triggered_by',
    'pages_processed', 'documents_persisted', 'from_nsu', 'to_nsu',
    'error_message', 'started_at', 'finished_at',
])]
class SyncRun extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
        ];
    }

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(SyncCursor::class, 'sync_cursor_id');
    }
}
