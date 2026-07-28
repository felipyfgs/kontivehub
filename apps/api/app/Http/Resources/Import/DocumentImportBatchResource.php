<?php

namespace App\Http\Resources\Import;

use App\Enums\ImportBatchStatus;
use App\Models\DocumentImportBatch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DocumentImportBatch */
final class DocumentImportBatchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $status = $this->status instanceof ImportBatchStatus
            ? $this->status->value
            : (string) $this->status;
        $terminal = $this->status instanceof ImportBatchStatus
            ? $this->status->isTerminal()
            : in_array($status, [
                'COMPLETED',
                'COMPLETED_WITH_ERRORS',
                'FAILED',
            ], true);
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
            'upload_complete' => $this->queued_at !== null
                || $this->processing_started_at !== null
                || $terminal,
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
