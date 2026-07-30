<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CommunicationMediaDeletionIntent extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'immutable_datetime',
            'dispatched_at' => 'immutable_datetime',
            'deleted_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
        ];
    }
}
