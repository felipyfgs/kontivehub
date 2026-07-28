<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'conversation_id',
    'version',
    'last_read_through_message_id',
    'updated_by_user_id',
    'updated_by_membership_id',
    'last_action',
])]
class CommunicationConversationReadState extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'version' => 'integer',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function lastReadThroughMessage(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'last_read_through_message_id');
    }

    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }

    public function updatedByMembership(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'updated_by_membership_id');
    }
}
