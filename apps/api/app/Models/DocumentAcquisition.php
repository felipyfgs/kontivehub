<?php

namespace App\Models;

use App\Enums\CaptureChannel;
use App\Enums\DocumentAcquisitionSource;
use App\Enums\DocumentArtifactQuality;
use App\Enums\SignatureVerificationResult;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'office_id', 'dfe_document_id', 'access_key', 'source', 'channel', 'nsu', 'sha256',
    'artifact_quality', 'signature_result',
    'is_canonical', 'bytes_diverge_from_canonical', 'quarantine_reason',
    'establishment_id', 'ma_outbound_retrieval_request_id', 'outbound_number_state_id',
    'office_distribution_cursor_id', 'document_import_batch_item_id',
    'metadata',
])]
class DocumentAcquisition extends Model
{
    use BelongsToOffice;

    protected function casts(): array
    {
        return [
            'source' => DocumentAcquisitionSource::class,
            'channel' => CaptureChannel::class,
            'artifact_quality' => DocumentArtifactQuality::class,
            'signature_result' => SignatureVerificationResult::class,
            'nsu' => 'integer',
            'is_canonical' => 'boolean',
            'bytes_diverge_from_canonical' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function dfeDocument(): BelongsTo
    {
        return $this->belongsTo(DfeDocument::class, 'dfe_document_id');
    }

    public function establishment(): BelongsTo
    {
        return $this->belongsTo(Establishment::class);
    }

    public function retrievalRequest(): BelongsTo
    {
        return $this->belongsTo(MaOutboundRetrievalRequest::class, 'ma_outbound_retrieval_request_id');
    }

    public function numberState(): BelongsTo
    {
        return $this->belongsTo(OutboundNumberState::class, 'outbound_number_state_id');
    }

    public function officeDistributionCursor(): BelongsTo
    {
        return $this->belongsTo(OfficeDistributionCursor::class, 'office_distribution_cursor_id');
    }

    public function importBatchItem(): BelongsTo
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
            'dfe_document_id' => $this->dfe_document_id,
            'access_key' => $this->access_key,
            'source' => $this->source->value,
            'channel' => $this->channel?->value,
            'nsu' => $this->nsu,
            'sha256' => $this->sha256,
            'artifact_quality' => $this->artifact_quality?->value,
            'signature_result' => $this->signature_result?->value,
            'is_canonical' => $this->is_canonical,
            'bytes_diverge_from_canonical' => $this->bytes_diverge_from_canonical,
            'quarantine_reason' => $this->quarantine_reason,
            // Sem vault, path ou batch spool
            'has_batch_item' => $this->document_import_batch_item_id !== null,
            'has_real_nsu' => $this->nsu !== null,
        ];
    }
}
