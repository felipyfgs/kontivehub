<?php

namespace App\Models;

use App\Enums\Communication\ConversationStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'identity_id',
    'merged_into_conversation_id',
    'status',
    'work_department_id',
    'assignee_membership_id',
    'priority',
    'snoozed_until',
    'resolved_at',
    'last_message_at',
    'lock_version',
    'purged_at',
    'tombstone_digest',
])]
class CommunicationConversation extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'status' => ConversationStatus::class,
            'priority' => 'integer',
            'lock_version' => 'integer',
            'snoozed_until' => 'immutable_datetime',
            'resolved_at' => 'immutable_datetime',
            'last_message_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'identity_id');
    }

    public function mergedIntoConversation(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_conversation_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(WorkDepartment::class, 'work_department_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'assignee_membership_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(CommunicationMessage::class, 'conversation_id')
            ->visibleToWorkspace()
            ->orderBy('occurred_at')
            ->orderBy('id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(CommunicationMessage::class, 'conversation_id')
            ->visibleToWorkspace()
            ->ofMany([
                'occurred_at' => 'max',
                'id' => 'max',
            ]);
    }

    public function unreadMessages(): HasMany
    {
        return $this->hasMany(CommunicationConversationUnreadMessage::class, 'conversation_id')
            ->whereHas(
                'message',
                fn (Builder $messages): Builder => $messages->visibleToWorkspace(),
            );
    }

    public function readState(): HasOne
    {
        return $this->hasOne(CommunicationConversationReadState::class, 'conversation_id');
    }

    public function clients(): BelongsToMany
    {
        return $this->belongsToMany(Client::class, 'communication_conversation_clients', 'conversation_id', 'client_id')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(CommunicationLabel::class, 'communication_conversation_labels', 'conversation_id', 'label_id')
            ->withPivot(['tenant_id', 'assigned_by_membership_id'])
            ->withTimestamps();
    }
}
