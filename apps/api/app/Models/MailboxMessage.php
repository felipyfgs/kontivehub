<?php

namespace App\Models;

use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'office_id',
    'client_id',
    'external_id',
    'message_hash',
    'source',
    'sensitivity_class',
    'category_code',
    'category_label',
    'sender_code',
    'sender_label',
    'subject_preview',
    'received_at_official',
    'due_at',
    'severity_hint',
    'official_read_indicator',
    'official_read_observed_at',
    'triage_status',
    'triage_by',
    'triage_at',
    'triage_note',
    'body_vault_object_id',
    'body_sha256',
    'body_content_type',
    'body_byte_size',
    'has_body',
    'attachment_count',
    'retention_until',
    'first_run_id',
    'last_run_id',
    'evidence_artifact_id',
    'metadata',
])]
class MailboxMessage extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'source' => MailboxSource::class,
            'received_at_official' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'official_read_indicator' => 'boolean',
            'official_read_observed_at' => 'immutable_datetime',
            'triage_status' => MailboxTriageStatus::class,
            'triage_at' => 'immutable_datetime',
            'body_byte_size' => 'integer',
            'has_body' => 'boolean',
            'attachment_count' => 'integer',
            'retention_until' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(MailboxAttachment::class, 'mailbox_message_id');
    }

    public function accessEvents(): HasMany
    {
        return $this->hasMany(MailboxAccessEvent::class, 'mailbox_message_id');
    }

    public function alert(): HasOne
    {
        return $this->hasOne(MailboxAlert::class, 'mailbox_message_id');
    }

    /**
     * Metadados de lista — sem corpo, vault_object_id ou anexo bruto.
     *
     * @return array<string, mixed>
     */
    public function toListArray(): array
    {
        return [
            'id' => $this->id,
            'office_id' => $this->office_id,
            'client_id' => $this->client_id,
            'external_id' => $this->external_id,
            'source' => $this->source?->value,
            'sensitivity_class' => $this->sensitivity_class,
            'category_code' => $this->category_code,
            'category_label' => $this->category_label,
            'sender_code' => $this->sender_code,
            'sender_label' => $this->sender_label,
            'subject_preview' => $this->subject_preview,
            'received_at_official' => $this->received_at_official?->toIso8601String(),
            'due_at' => $this->due_at?->toIso8601String(),
            'severity_hint' => $this->severity_hint,
            'official_read_indicator' => $this->official_read_indicator,
            'triage_status' => $this->triage_status?->value,
            'has_body' => $this->has_body,
            'attachment_count' => $this->attachment_count,
            'body_byte_size' => $this->body_byte_size,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Detalhe autorizado (ainda sem bytes do corpo — download separado).
     *
     * @return array<string, mixed>
     */
    public function toDetailArray(): array
    {
        $base = $this->toListArray();
        $base['body_content_type'] = $this->body_content_type;
        $base['body_sha256'] = $this->body_sha256;
        $base['retention_until'] = $this->retention_until?->toIso8601String();
        $base['official_read_observed_at'] = $this->official_read_observed_at?->toIso8601String();
        $base['triage_by'] = $this->triage_by;
        $base['triage_at'] = $this->triage_at?->toIso8601String();
        $base['triage_note'] = $this->triage_note;
        $base['attachments'] = $this->relationLoaded('attachments')
            ? $this->attachments->map(fn (MailboxAttachment $a) => $a->toPublicArray())->all()
            : [];

        return $base;
    }
}
