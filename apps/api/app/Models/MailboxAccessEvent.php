<?php

namespace App\Models;

use App\Enums\MailboxAccessAction;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'mailbox_message_id',
    'mailbox_attachment_id',
    'user_id',
    'action',
    'correlation_id',
    'ip_address',
    'metadata',
    'created_at',
])]
class MailboxAccessEvent extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'action' => MailboxAccessAction::class,
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'mailbox_message_id');
    }

    public function attachment(): BelongsTo
    {
        return $this->belongsTo(MailboxAttachment::class, 'mailbox_attachment_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'mailbox_message_id' => $this->mailbox_message_id,
            'mailbox_attachment_id' => $this->mailbox_attachment_id,
            'user_id' => $this->user_id,
            'action' => $this->action?->value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
