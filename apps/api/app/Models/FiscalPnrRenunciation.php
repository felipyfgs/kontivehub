<?php

namespace App\Models;

use App\Casts\FiscalSourceProvenanceCast;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Última evidência sanitizada de uma renúncia PNR por cliente/escritório.
 *
 * `receipt_vault_object_id` é deliberadamente oculto: a API de monitoramento
 * expõe somente o descritor do documento, nunca uma chave do cofre.
 */
#[Fillable([
    'office_id',
    'client_id',
    'contributor_cnpj',
    'renunciation_id',
    'status',
    'history_evidence_version',
    'status_evidence_version',
    'source_provenance',
    'summary_sanitized',
    'occurred_at',
    'observed_at',
    'refreshed_at',
    'receipt_vault_object_id',
    'receipt_sha256',
    'receipt_mime_type',
    'receipt_byte_size',
    'receipt_observed_at',
])]
#[Hidden(['receipt_vault_object_id', 'receipt_sha256'])]
class FiscalPnrRenunciation extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'renunciation_id' => 'integer',
            'source_provenance' => FiscalSourceProvenanceCast::class,
            'summary_sanitized' => 'array',
            'occurred_at' => 'immutable_datetime',
            'observed_at' => 'immutable_datetime',
            'refreshed_at' => 'immutable_datetime',
            'receipt_byte_size' => 'integer',
            'receipt_observed_at' => 'immutable_datetime',
        ];
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function hasReceiptDescriptor(): bool
    {
        return $this->receipt_vault_object_id !== null
            && $this->receipt_sha256 !== null
            && $this->receipt_byte_size !== null
            && $this->receipt_byte_size > 0;
    }

    /** @return array<string, mixed> */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'client_id' => $this->client_id,
            'renunciation_id' => $this->renunciation_id,
            'status' => $this->status,
            'source_provenance' => $this->source_provenance?->value,
            'summary' => $this->summary_sanitized,
            'occurred_at' => $this->occurred_at?->toIso8601String(),
            'observed_at' => $this->observed_at?->toIso8601String(),
            'refreshed_at' => $this->refreshed_at?->toIso8601String(),
            'receipt' => $this->hasReceiptDescriptor() ? [
                'mime_type' => $this->receipt_mime_type,
                'byte_size' => $this->receipt_byte_size,
                'observed_at' => $this->receipt_observed_at?->toIso8601String(),
            ] : null,
        ];
    }
}
