<?php

namespace App\Jobs;

use App\Contracts\SecureObjectStore;
use App\Enums\ImportBatchItemStatus;
use App\Enums\ImportBatchStatus;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use App\Services\Import\DocumentImportBatchService;
use App\Services\Import\OutboundXmlIngestionService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Processa lote de importação de forma idempotente (itens PENDING).
 */
class ProcessDocumentImportBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 900;

    public function __construct(public int $batchId)
    {
        $this->onQueue((string) config('import.queue', 'import-xml'));
    }

    public function handle(
        SecureObjectStore $store,
        OutboundXmlIngestionService $ingestion,
        DocumentImportBatchService $batches,
    ): void {
        $batch = DocumentImportBatch::query()->find($this->batchId);
        if ($batch === null) {
            return;
        }

        if ($batch->status->isTerminal()) {
            return;
        }

        $batch->status = ImportBatchStatus::Processing;
        $batch->processing_started_at = $batch->processing_started_at ?? now();
        $batch->save();

        $pending = DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $batch->id)
            ->where('status', ImportBatchItemStatus::Pending)
            ->orderBy('item_index')
            ->get();

        foreach ($pending as $item) {
            $this->processItem($batch, $item, $store, $ingestion, $batches);
        }

        $batches->recomputeBatchCounters($batch->fresh() ?? $batch);
    }

    private function processItem(
        DocumentImportBatch $batch,
        DocumentImportBatchItem $item,
        SecureObjectStore $store,
        OutboundXmlIngestionService $ingestion,
        DocumentImportBatchService $batches,
    ): void {
        try {
            $spool = $batches->resolveSpoolForItem($item);
            if ($spool === null) {
                $item->status = ImportBatchItemStatus::Failed;
                $item->result_code = 'MISSING_SPOOL';
                $item->result_message = $item->entry_name
                    ? 'Upload privado ausente — reenvie o ZIP original para reprocessar este item expandido.'
                    : 'Upload privado ausente.';
                $item->attempts = (int) $item->attempts + 1;
                $item->processed_at = now();
                $item->save();

                return;
            }

            $aad = [
                'office_id' => $batch->office_id,
                'purpose' => 'import-spool',
                'sha256' => $spool['sha256'],
            ];
            try {
                $bytes = $store->get($spool['object_id'], $aad);
            } catch (Throwable) {
                $bytes = $store->get($spool['object_id'], [
                    'office_id' => $batch->office_id,
                    'purpose' => 'import-spool',
                    'sha256' => (string) $spool['sha256'],
                ]);
            }

            // Nome do arquivo de topo (ZIP ou XML) — filhos usam source_name do parent ZIP.
            $uploadName = $item->source_name ?: 'upload.bin';
            $file = UploadedFile::fake()->createWithContent($uploadName, $bytes);
            $report = $ingestion->ingestUploads(
                (int) $batch->office_id,
                $batch->client_id,
                [$file],
            );

            $batches->applyReportToTopLevelItem($batch, $item, $report['items']);
        } catch (Throwable $e) {
            Log::warning('import.batch.item_failed', [
                'batch_id' => $batch->public_id,
                'item_index' => $item->item_index,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ]);
            $item->status = ImportBatchItemStatus::Failed;
            $item->result_code = 'FAILED';
            $item->result_message = 'Falha ao processar item.';
            $item->attempts = (int) $item->attempts + 1;
            $item->processed_at = now();
            $item->save();
        }
    }
}
