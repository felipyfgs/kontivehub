<?php

namespace App\Models;

use App\Enums\Communication\ConversationBulkItemStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'item_index',
    'conversation_id',
    'live_conversation_id',
    'resolved_conversation_id',
    'inbox_id',
    'live_inbox_id',
    'lock_version',
    'through_message_id',
    'read_state_version',
    'status',
    'result_code',
    'result_message',
    'attempts',
    'processed_at',
])]
class CommunicationConversationBulkOperationItem extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ConversationBulkItemStatus::class,
            'item_index' => 'integer',
            'lock_version' => 'integer',
            'through_message_id' => 'integer',
            'read_state_version' => 'integer',
            'attempts' => 'integer',
            'processed_at' => 'immutable_datetime',
        ];
    }

    public function operation(): BelongsTo
    {
        return $this->belongsTo(
            CommunicationConversationBulkOperation::class,
            'bulk_operation_id',
        );
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'conversation_id');
    }

    public function resolvedConversation(): BelongsTo
    {
        return $this->belongsTo(CommunicationConversation::class, 'resolved_conversation_id');
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }
}
