<?php

namespace App\Models;

use App\Enums\MailboxDteStatus;
use App\Enums\MailboxMessagesConsultStatus;
use App\Enums\MailboxSource;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'client_id',
    'dte_status',
    'dte_source',
    'dte_observed_at',
    'last_dte_run_id',
    'messages_status',
    'messages_source',
    'messages_observed_at',
    'last_list_run_id',
    'official_unread_count',
    'stored_message_count',
    'new_messages_indicator',
    'new_messages_indicator_observed_at',
    'last_new_messages_indicator_run_id',
    'metadata',
])]
class MailboxContributorState extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'dte_status' => MailboxDteStatus::class,
            'dte_source' => MailboxSource::class,
            'dte_observed_at' => 'immutable_datetime',
            'messages_status' => MailboxMessagesConsultStatus::class,
            'messages_source' => MailboxSource::class,
            'messages_observed_at' => 'immutable_datetime',
            'official_unread_count' => 'integer',
            'stored_message_count' => 'integer',
            'new_messages_indicator' => 'integer',
            'new_messages_indicator_observed_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * Projeção pública: DTE e mensagens nunca fundidos.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'dte' => [
                'status' => $this->dte_status?->value ?? MailboxDteStatus::Unknown->value,
                'source' => $this->dte_source?->value,
                'observed_at' => $this->dte_observed_at?->toIso8601String(),
            ],
            'messages' => [
                'status' => $this->messages_status?->value ?? MailboxMessagesConsultStatus::Unknown->value,
                'source' => $this->messages_source?->value,
                'observed_at' => $this->messages_observed_at?->toIso8601String(),
                'official_unread_count' => $this->official_unread_count,
                'stored_message_count' => $this->stored_message_count,
            ],
            'new_messages_indicator' => [
                'value' => $this->new_messages_indicator,
                'semantic' => 'UNOPENED_ONLY',
                'observed_at' => $this->new_messages_indicator_observed_at?->toIso8601String(),
                'reconciles_mailbox' => false,
            ],
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
