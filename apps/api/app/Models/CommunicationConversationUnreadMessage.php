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
    'message_id',
])]
class CommunicationConversationUnreadMessage extends Model
{
    use BelongsToTenant;

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id')
            ->visibleToWorkspace();
    }
}
