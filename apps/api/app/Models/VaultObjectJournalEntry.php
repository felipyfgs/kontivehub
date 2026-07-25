<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'object_id',
    'purpose',
    'crypto_key_version',
    'rewrap_status',
    'retention_class',
    'retain_until',
    'orphaned_at',
    'deleted_at',
    'content_sha256',
    'office_id',
    'metadata',
])]
class VaultObjectJournalEntry extends Model
{
    protected $table = 'vault_object_journal';

    protected function casts(): array
    {
        return [
            'retain_until' => 'immutable_datetime',
            'orphaned_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }
}
