<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

#[Fillable([
    'office_id',
    'inbox_id',
    'conversation_id',
    'message_id',
    'actor_membership_id',
    'type',
    'gateway_event_id',
    'payload_digest',
    'payload',
    'occurred_at',
    'created_at',
])]
class CommunicationEvent extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Eventos de comunicação são append-only.');
        });
        static::deleting(static function (): never {
            throw new LogicException('Eventos de comunicação são append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
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

    public function message(): BelongsTo
    {
        return $this->belongsTo(CommunicationMessage::class, 'message_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(OfficeMembership::class, 'actor_membership_id');
    }
}
