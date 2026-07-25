<?php

namespace App\Console\Commands;

use App\Contracts\SecureObjectStore;
use App\Enums\ImportBatchStatus;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use Illuminate\Console\Command;
use Throwable;

class PurgeExpiredImportSpoolsCommand extends Command
{
    protected $signature = 'import:purge-expired-spools {--limit=200 : Máximo de batches por execução}';

    protected $description = 'Remove spools de importação expirados (preserva documentos aceitos e metadados do batch)';

    public function handle(SecureObjectStore $store): int
    {
        $n = 0;
        DocumentImportBatch::query()
            ->whereNotNull('spool_expires_at')
            ->where('spool_expires_at', '<=', now())
            ->whereIn('status', [
                ImportBatchStatus::Completed->value,
                ImportBatchStatus::CompletedWithErrors->value,
                ImportBatchStatus::Failed->value,
            ])
            ->orderBy('id')
            ->limit((int) $this->option('limit'))
            ->each(function (DocumentImportBatch $batch) use ($store, &$n): void {
                DocumentImportBatchItem::query()
                    ->where('document_import_batch_id', $batch->id)
                    ->whereNotNull('spool_vault_object_id')
                    ->each(function (DocumentImportBatchItem $item) use ($store): void {
                        try {
                            $store->delete((string) $item->spool_vault_object_id);
                        } catch (Throwable) {
                            // best-effort
                        }
                        $item->spool_vault_object_id = null;
                        $item->save();
                    });

                if ($batch->spool_vault_object_id) {
                    try {
                        $store->delete((string) $batch->spool_vault_object_id);
                    } catch (Throwable) {
                        // best-effort
                    }
                    $batch->spool_vault_object_id = null;
                }
                $batch->spool_expires_at = null;
                $batch->save();
                $n++;
            });

        $this->info("Spools purgados de {$n} batch(es).");

        return self::SUCCESS;
    }
}
