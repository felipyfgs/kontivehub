<?php

namespace App\Models;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\OutboxStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'message_id',
    'message_batch_id',
    'command_id',
    'effect_key',
    'session_id',
    'type',
    'payload_encrypted',
    'payload_digest',
    'status',
    'attempt_count',
    'available_at',
    'locked_at',
    'accepted_at',
    'last_attempt_at',
    'last_error_code',
    'last_error_message',
])]
class CommunicationOutboxEntry extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'type' => GatewayCommandType::class,
            'payload_encrypted' => 'encrypted:array',
            'status' => OutboxStatus::class,
            'attempt_count' => 'integer',
            'available_at' => 'immutable_datetime',
            'locked_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
        ];
    }

    public function inbox(): BelongsTo
    {
        return $this->belongsTo(CommunicationInbox::class, 'inbox_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    public function messageBatch(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessageBatch::class, 'message_batch_id');
    }
}
