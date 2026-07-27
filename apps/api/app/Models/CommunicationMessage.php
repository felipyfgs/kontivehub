<?php

namespace App\Models;

use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Enums\Communication\MessageStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'tenant_id',
    'inbox_id',
    'conversation_id',
    'identity_id',
    'reply_to_message_id',
    'author_membership_id',
    'client_communication_dispatch_id',
    'direction',
    'kind',
    'provider_type',
    'source',
    'status',
    'body_encrypted',
    'content_encrypted',
    'provider_message_id',
    'gateway_event_id',
    'content_digest',
    'metadata',
    'occurred_at',
    'accepted_at',
    'sent_at',
    'delivered_at',
    'read_at',
    'played_at',
    'revoked_at',
    'failed_at',
    'purged_at',
])]
class CommunicationMessage extends Model
{
    use BelongsToTenant;

    protected function casts(): array
    {
        return [
            'direction' => MessageDirection::class,
            'kind' => MessageKind::class,
            'source' => MessageSource::class,
            'status' => MessageStatus::class,
            'body_encrypted' => 'encrypted',
            'content_encrypted' => 'encrypted:array',
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'read_at' => 'immutable_datetime',
            'played_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'failed_at' => 'immutable_datetime',
            'purged_at' => 'immutable_datetime',
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

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CommunicationIdentity::class, 'identity_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(TenantMembership::class, 'author_membership_id');
    }

    public function fiscalDispatch(): BelongsTo
    {
        return $this->belongsTo(ClientCommunicationDispatch::class, 'client_communication_dispatch_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(CommunicationAttachment::class, 'message_id');
    }
}
