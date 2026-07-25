<?php

namespace App\Models;

use App\Enums\ImportBatchStatus;
use App\Models\Concerns\BelongsToOffice;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'public_id', 'office_id', 'created_by', 'client_id', 'establishment_id',
    'status', 'idempotency_key', 'selection_digest', 'file_count', 'item_count',
    'imported_count', 'duplicate_count', 'unmatched_count', 'invalid_count',
    'failed_count', 'quarantined_count', 'compressed_bytes', 'uncompressed_bytes',
    'spool_vault_object_id', 'error_code', 'error_message',
    'queued_at', 'processing_started_at', 'completed_at', 'spool_expires_at', 'quotas',
])]
#[Hidden(['spool_vault_object_id'])]
class DocumentImportBatch extends Model
{
    use BelongsToOffice;
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ImportBatchStatus::class,
            'file_count' => 'integer',
            'item_count' => 'integer',
            'imported_count' => 'integer',
            'duplicate_count' => 'integer',
            'unmatched_count' => 'integer',
            'invalid_count' => 'integer',
            'failed_count' => 'integer',
            'quarantined_count' => 'integer',
            'compressed_bytes' => 'integer',
            'uncompressed_bytes' => 'integer',
            'queued_at' => 'immutable_datetime',
            'processing_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'spool_expires_at' => 'immutable_datetime',
            'quotas' => 'array',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DocumentImportBatchItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toPublicArray(): array
    {
        $status = $this->status instanceof ImportBatchStatus
            ? $this->status->value
            : (string) $this->status;

        $terminal = $this->status instanceof ImportBatchStatus
            ? $this->status->isTerminal()
            : in_array($status, ['COMPLETED', 'COMPLETED_WITH_ERRORS', 'FAILED'], true);

        $processed = (int) $this->imported_count
            + (int) $this->duplicate_count
            + (int) $this->unmatched_count
            + (int) $this->invalid_count
            + (int) $this->failed_count
            + (int) $this->quarantined_count;

        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'status' => $status,
            'is_terminal' => $terminal,
            'upload_complete' => $this->queued_at !== null || $this->processing_started_at !== null || $terminal,
            'processing_complete' => $terminal,
            'client_id' => $this->client_id,
            'establishment_id' => $this->establishment_id,
            'created_by' => $this->created_by,
            'file_count' => $this->file_count,
            'item_count' => $this->item_count,
            'processed_count' => $processed,
            'imported_count' => $this->imported_count,
            'duplicate_count' => $this->duplicate_count,
            'unmatched_count' => $this->unmatched_count,
            'invalid_count' => $this->invalid_count,
            'failed_count' => $this->failed_count,
            'quarantined_count' => $this->quarantined_count,
            'compressed_bytes' => $this->compressed_bytes,
            'uncompressed_bytes' => $this->uncompressed_bytes,
            'error_code' => $this->error_code,
            'error_message' => $this->error_message,
            'queued_at' => $this->queued_at?->toIso8601String(),
            'processing_started_at' => $this->processing_started_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
