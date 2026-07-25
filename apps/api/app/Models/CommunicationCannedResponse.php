<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Database\Factories\CommunicationCannedResponseFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'title',
    'shortcut',
    'body_encrypted',
    'is_active',
    'lock_version',
    'created_by_membership_id',
])]
class CommunicationCannedResponse extends Model
{
    /** @use HasFactory<CommunicationCannedResponseFactory> */
    use BelongsToOffice, HasFactory;

    protected function casts(): array
    {
        return [
            'body_encrypted' => 'encrypted',
            'is_active' => 'boolean',
            'lock_version' => 'integer',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'created_by_membership_id');
    }
}
