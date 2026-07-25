<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'category',
    'status',
    'trigger',
    'revoked_at',
    'eligible_purge_at',
    'purged_at',
    'requested_by_user_id',
    'reason',
    'summary',
])]
class SerproRetentionJob extends Model
{
    protected function casts(): array
    {
        return [
            'revoked_at' => 'immutable_datetime',
            'eligible_purge_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
            'summary' => 'array',
        ];
    }

    public function office(): BelongsTo
    {
        return $this->belongsTo(Office::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toSanitizedArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'category' => $this->category,
            'status' => $this->status,
            'trigger' => $this->trigger,
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'eligible_purge_at' => $this->eligible_purge_at?->toIso8601String(),
            'purged_at' => $this->purged_at?->toIso8601String(),
            'reason' => $this->reason,
            'summary' => $this->summary,
        ];
    }
}
