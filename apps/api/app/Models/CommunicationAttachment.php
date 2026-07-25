<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'message_id',
    'object_id',
    'original_name_encrypted',
    'mime_type',
    'size_bytes',
    'sha256',
    'storage_context',
    'disposition',
    'purged_at',
])]
class CommunicationAttachment extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'original_name_encrypted' => 'encrypted',
            'size_bytes' => 'integer',
            'storage_context' => 'array',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }
}
