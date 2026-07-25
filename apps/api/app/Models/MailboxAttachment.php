<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id',
    'mailbox_message_id',
    'external_id',
    'filename_sanitized',
    'content_type',
    'vault_object_id',
    'content_sha256',
    'byte_size',
    'sensitivity_class',
    'retention_until',
    'metadata',
    'created_at',
])]
class MailboxAttachment extends Model
{
    use BelongsToOffice;

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'byte_size' => 'integer',
            'retention_until' => 'immutable_datetime',
            'metadata' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailboxMessage::class, 'mailbox_message_id');
    }

    /**
     * Metadados públicos — sem vault_object_id.
     *
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'mailbox_message_id' => $this->mailbox_message_id,
            'external_id' => $this->external_id,
            'filename_sanitized' => $this->filename_sanitized,
            'content_type' => $this->content_type,
            'content_sha256' => $this->content_sha256,
            'byte_size' => $this->byte_size,
            'sensitivity_class' => $this->sensitivity_class,
            'retention_until' => $this->retention_until?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
