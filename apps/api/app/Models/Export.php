<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'user_id', 'status', 'filters', 'include_events',
    'storage_path', 'byte_size', 'files_count', 'error_message', 'expires_at', 'completed_at',
])]
#[Hidden(['storage_path'])]
class Export extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'include_events' => 'boolean',
            'expires_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
