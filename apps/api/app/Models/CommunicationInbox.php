<?php

namespace App\Models;

use App\Enums\Communication\InboxStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'office_id',
    'name',
    'session_id',
    'address_encrypted',
    'address_hash',
    'address_masked',
    'status',
    'is_enabled',
    'is_default',
    'work_department_id',
    'lock_version',
    'settings',
    'connected_at',
    'last_seen_at',
    'revoked_at',
])]
class CommunicationInbox extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'address_encrypted' => 'encrypted',
            'status' => InboxStatus::class,
            'is_enabled' => 'boolean',
            'is_default' => 'boolean',
            'lock_version' => 'integer',
            'settings' => 'array',
            'connected_at' => 'immutable_datetime',
            'last_seen_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(CommunicationInboxMember::class, 'inbox_id');
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(CommunicationConversation::class, 'inbox_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'inbox_id');
    }
}
