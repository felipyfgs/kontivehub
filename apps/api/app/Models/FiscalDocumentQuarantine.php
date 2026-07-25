<?php

namespace App\Models;

use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Objeto fiscal preservado fora do catálogo comum até resolução.
 * Nunca serializa vault_object_id ou bytes.
 */
#[Fillable([
    'office_id', 'sha256', 'vault_object_id', 'byte_size', 'access_key',
    'issuer_cnpj', 'recipient_cnpj', 'model', 'schema_family', 'reason',
    'source', 'channel', 'nsu', 'office_distribution_cursor_id',
    'document_import_batch_item_id', 'resolution_status', 'resolved_by',
    'resolved_at', 'resolution_code', 'resolution_notes', 'promoted_dfe_document_id',
    'metadata',
])]
#[Hidden(['vault_object_id'])]
class FiscalDocumentQuarantine extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected $table = 'fiscal_document_quarantine';

    protected function casts(): array
    {
        return [
            'reason' => QuarantineReason::class,
            'source' => DocumentAcquisitionSource::class,
            'channel' => CaptureChannel::class,
            'resolution_status' => QuarantineResolutionStatus::class,
            'nsu' => 'integer',
            'byte_size' => 'integer',
            'resolved_at' => 'immutable_datetime',
            'metadata' => 'array',
        ];
    }

    public function cursor(): BelongsTo
    {
        return $this->belongsTo(OfficeDistributionCursor::class, 'office_distribution_cursor_id');
    }

    public function batchItem(): BelongsTo
    {
        return $this->belongsTo(DocumentImportBatchItem::class, 'document_import_batch_item_id');
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        return [
            'id' => $this->id,
            'sha256' => $this->sha256,
            'byte_size' => $this->byte_size,
            'access_key' => $this->access_key,
            'issuer_cnpj' => $this->issuer_cnpj,
            'recipient_cnpj' => $this->recipient_cnpj,
            'model' => $this->model,
            'schema_family' => $this->schema_family,
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'source' => $this->source->value,
            'channel' => $this->channel?->value,
            'nsu' => $this->nsu,
            'resolution_status' => $this->resolution_status->value,
            'resolved_at' => $this->resolved_at?->toIso8601String(),
            'resolution_code' => $this->resolution_code,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
