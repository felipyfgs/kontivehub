<?php

namespace App\Actions\Import;

use App\Exceptions\DocumentImportBatchApiException;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use App\Services\Audit\AuditLogger;
use App\Services\Import\DocumentImportBatchQuery;
use App\Services\Import\DocumentImportBatchService;
use RuntimeException;

final class RetryDocumentImportBatchItemAction
{
    public function __construct(
        private readonly DocumentImportBatchQuery $query,
        private readonly DocumentImportBatchService $batches,
        private readonly AuditLogger $audit,
    ) {}

    public function execute(
        DocumentImportBatch $batch,
        int $itemId,
    ): DocumentImportBatchItem {
        $item = $this->query->findItem($batch, $itemId);

        try {
            $updated = $this->batches->retryItem($item);
        } catch (RuntimeException $error) {
            $this->audit->record(
                'documents.import_batch.retry',
                'FAILED',
                $batch,
                [
                    'item_id' => $item->id,
                    'message' => $error->getMessage(),
                ],
            );

            throw DocumentImportBatchApiException::retryRejected(
                $error->getMessage(),
            );
        }

        $this->audit->record(
            'documents.import_batch.retry',
            'SUCCESS',
            $batch,
            [
                'item_id' => $updated->id,
                'item_index' => $updated->item_index,
                'status' => $updated->status->value,
                'result_code' => $updated->result_code,
            ],
        );

        return $updated->load('batch:id,public_id');
    }
}
