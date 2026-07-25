<?php

namespace App\Models;

use App\Enums\SyncCursorStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id', 'establishment_id', 'environment', 'last_nsu', 'status',
    'consecutive_decode_failures', 'attempts', 'next_sync_at', 'last_success_at',
    'locked_at', 'lock_owner', 'last_error',
])]
class SyncCursor extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'status' => SyncCursorStatus::class,
            'last_nsu' => 'integer',
            'consecutive_decode_failures' => 'integer',
            'attempts' => 'integer',
            'next_sync_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
        ];
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(SyncRun::class);
    }
}
