<?php

namespace App\Models;

use App\Enums\MailboxAlertSeverity;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Alerta sanitizado — título/body sem corpo de mensagem, anexo ou token.
 */
#[Fillable([
    'office_id',
    'client_id',
    'mailbox_message_id',
    'severity',
    'title',
    'body',
    'deep_link',
    'is_active',
    'dismissed_at',
    'metadata',
])]
class MailboxAlert extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'severity' => MailboxAlertSeverity::class,
            'is_active' => 'boolean',
            'dismissed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'mailbox_message_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'mailbox_message_id' => $this->mailbox_message_id,
            'severity' => $this->severity?->value,
            'title' => $this->title,
            'body' => $this->body,
            'deep_link' => $this->deep_link,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
