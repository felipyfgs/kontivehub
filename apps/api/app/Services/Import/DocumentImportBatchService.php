<?php

namespace App\Services\Import;

use App\Contracts\SecureObjectStore;
use App\Enums\ImportBatchItemStatus;
use App\Enums\ImportBatchStatus;
use App\Jobs\ProcessDocumentImportBatchJob;
use App\Models\Client;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use App\Models\Establishment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Admissão e metadados de lotes de importação assíncrona.
 * Processamento pesado fica nos jobs (quando IMPORT_ASYNC_BATCHES_ENABLED).
 */
final class DocumentImportBatchService
{
    public function __construct(
        private readonly SecureObjectStore $store,
        private readonly OutboundXmlIngestionService $syncIngestion,
    ) {}

    /**
     * @param  list<UploadedFile>  $files
     * @return array{batch: DocumentImportBatch, created: bool}
     */
    public function admit(
        int $officeId,
        User $actor,
        array $files,
        ?int $clientId = null,
        ?int $establishmentId = null,
        ?string $idempotencyKey = null,
    ): array {
        $this->assertAdmissionLimits($files);
        $this->assertTenancyScope($officeId, $clientId, $establishmentId);

        $digest = $this->selectionDigest($files, $clientId, $establishmentId);

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = DocumentImportBatch::query()
                ->where('office_id', $officeId)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return ['batch' => $existing, 'created' => false];
            }
        }

        $byDigest = DocumentImportBatch::query()
            ->where('office_id', $officeId)
            ->where('selection_digest', $digest)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->whereNotIn('status', [ImportBatchStatus::Failed->value])
            ->first();
        if ($byDigest !== null && ($idempotencyKey === null || $idempotencyKey === '')) {
            return ['batch' => $byDigest, 'created' => false];
        }

        $compressed = 0;
        $fileMeta = [];
        foreach ($files as $i => $file) {
            $bytes = (string) file_get_contents($file->getRealPath() ?: '');
            $compressed += strlen($bytes);
            $name = $this->sanitizeName($file->getClientOriginalName() ?: "file-{$i}.bin");
            $objectId = $this->store->put($bytes, [
                'office_id' => $officeId,
                'purpose' => 'import-spool',
                'sha256' => hash('sha256', $bytes),
            ]);
            $fileMeta[] = [
                'name' => $name,
                'bytes' => $bytes,
                'size' => strlen($bytes),
                'sha256' => hash('sha256', $bytes),
                'object_id' => $objectId,
                'is_zip' => $this->looksLikeZip($name, $bytes),
            ];
            unset($bytes);
        }

        $async = (bool) config('import.async_batches_enabled', false);

        $batch = DB::transaction(function () use (
            $officeId,
            $actor,
            $clientId,
            $establishmentId,
            $idempotencyKey,
            $digest,
            $fileMeta,
            $compressed,
            $async,
        ): DocumentImportBatch {
            $batch = DocumentImportBatch::query()->create([
                'public_id' => (string) Str::uuid(),
                'office_id' => $officeId,
                'created_by' => $actor->id,
                'client_id' => $clientId,
                'establishment_id' => $establishmentId,
                'status' => $async ? ImportBatchStatus::Queued : ImportBatchStatus::Processing,
                'idempotency_key' => $idempotencyKey ?: null,
                'selection_digest' => $digest,
                'file_count' => count($fileMeta),
                'item_count' => 0,
                'compressed_bytes' => $compressed,
                'spool_expires_at' => now()->addSeconds((int) config('import.spool_retention_seconds', 604800)),
                'queued_at' => now(),
                'quotas' => [
                    'max_top_level_files' => config('import.max_top_level_files'),
                    'max_request_compressed_bytes' => config('import.max_request_compressed_bytes'),
                ],
            ]);

            $index = 0;
            foreach ($fileMeta as $meta) {
                DocumentImportBatchItem::query()->create([
                    'office_id' => $officeId,
                    'document_import_batch_id' => $batch->id,
                    'item_index' => $index++,
                    'source_name' => $meta['name'],
                    'sha256' => $meta['sha256'],
                    'byte_size' => $meta['size'],
                    'status' => ImportBatchItemStatus::Pending,
                    'spool_vault_object_id' => $meta['object_id'],
                ]);
            }

            $batch->item_count = $index;
            $batch->save();

            return $batch;
        });

        if (! $async) {
            $this->processSynchronously($batch, $fileMeta, $clientId);
        } else {
            ProcessDocumentImportBatchJob::dispatch($batch->id);
        }

        return ['batch' => $batch->fresh(), 'created' => true];
    }

    public function retryItem(DocumentImportBatchItem $item): DocumentImportBatchItem
    {
        if (! $item->status->isRetryable()) {
            throw new RuntimeException('Somente itens UNMATCHED ou FAILED podem ser reprocessados.');
        }

        if ($item->spool_vault_object_id === null) {
            $parentSpool = $this->resolveParentSpoolObjectId($item);
            if ($parentSpool === null) {
                throw new RuntimeException(
                    'Upload privado ausente — reenvie o ZIP original para reprocessar este item expandido.'
                );
            }
            // Não persiste o spool no filho: o job resolve o parent no processamento.
        }

        $item->status = ImportBatchItemStatus::Pending;
        $item->result_code = null;
        $item->result_message = null;
        $item->processed_at = null;
        $item->save();

        $batch = $item->batch;
        if ($batch && $batch->status->isTerminal()) {
            $batch->status = ImportBatchStatus::Queued;
            $batch->completed_at = null;
            $batch->save();
        }

        ProcessDocumentImportBatchJob::dispatch((int) $item->document_import_batch_id);

        return $item->fresh();
    }

    /**
     * Aplica o relatório de ingestão a um item de topo ou a uma retentativa de filho de ZIP.
     *
     * - 1 resultado: atualiza o item in-place (mantém spool).
     * - N resultados (ZIP): parent vira resumo com spool (ZIP_EXPANDED); filhos sem spool.
     * - Retentativa de filho (entry_name preenchido): casa o resultado no relatório e atualiza só o item.
     *
     * @param  list<array<string, mixed>>  $reportItems
     */
    public function applyReportToTopLevelItem(
        DocumentImportBatch $batch,
        DocumentImportBatchItem $item,
        array $reportItems,
    ): void {
        if ($reportItems === []) {
            $item->status = ImportBatchItemStatus::Failed;
            $item->result_code = 'FAILED';
            $item->result_message = 'Sem resultado';
            $item->attempts = (int) $item->attempts + 1;
            $item->processed_at = now();
            $item->save();

            return;
        }

        // Filho expandido (retentativa ou reprocessamento pontual).
        if ($item->entry_name !== null && $item->entry_name !== '') {
            $row = $this->matchReportRow($item, $reportItems);
            $this->fillItemFromReportRow($item, $row);
            $item->attempts = (int) $item->attempts + 1;
            $item->processed_at = now();
            // Filhos continuam sem spool próprio.
            $item->spool_vault_object_id = null;
            $item->save();

            return;
        }

        if (count($reportItems) === 1) {
            $row = $reportItems[0];
            $this->fillItemFromReportRow($item, $row);
            $item->attempts = (int) $item->attempts + 1;
            $item->processed_at = now();
            // Mantém spool do arquivo de topo (XML avulso ou ZIP com 1 entrada/erro).
            $item->save();

            return;
        }

        $this->expandZipIntoParentAndChildren($batch, $item, $reportItems);
    }

    /**
     * Resolve bytes do spool do item, ou do parent ZIP_EXPANDED (mesmo source_name).
     *
     * @return array{object_id: string, sha256: ?string}|null
     */
    public function resolveSpoolForItem(DocumentImportBatchItem $item): ?array
    {
        if ($item->spool_vault_object_id !== null) {
            return [
                'object_id' => (string) $item->spool_vault_object_id,
                'sha256' => $item->sha256,
            ];
        }

        $parent = $this->findZipParentItem($item);
        if ($parent === null || $parent->spool_vault_object_id === null) {
            return null;
        }

        return [
            'object_id' => (string) $parent->spool_vault_object_id,
            'sha256' => $parent->sha256,
        ];
    }

    /**
     * Recomputa contadores e status terminal a partir dos itens do lote.
     * Parent ZIP (result_code ZIP_EXPANDED) é resumo e não entra nos contadores.
     */
    public function recomputeBatchCounters(DocumentImportBatch $batch): void
    {
        $items = DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $batch->id)
            ->get();

        $resultItems = $items->filter(
            fn (DocumentImportBatchItem $i): bool => $i->result_code !== 'ZIP_EXPANDED'
        );

        $imported = $resultItems->where('status', ImportBatchItemStatus::Imported)->count();
        $dup = $resultItems->where('status', ImportBatchItemStatus::Duplicate)->count();
        $invalid = $resultItems->where('status', ImportBatchItemStatus::Invalid)->count();
        $quarantined = $resultItems->where('status', ImportBatchItemStatus::Quarantined)->count();
        $unmatched = $resultItems->where('status', ImportBatchItemStatus::Unmatched)->count();
        // failed_count não inclui INVALID (campo próprio) — evita double-count no report legado
        $failed = $resultItems->whereIn('status', [
            ImportBatchItemStatus::Failed,
            ImportBatchItemStatus::Unsupported,
            ImportBatchItemStatus::ClientMismatch,
        ])->count();
        $pending = $resultItems->where('status', ImportBatchItemStatus::Pending)->count();
        $problem = $failed + $invalid + $unmatched + $quarantined;

        $batch->item_count = $resultItems->count();
        $batch->imported_count = $imported;
        $batch->duplicate_count = $dup;
        $batch->failed_count = $failed;
        $batch->unmatched_count = $unmatched;
        $batch->invalid_count = $invalid;
        $batch->quarantined_count = $quarantined;

        if ($pending > 0) {
            $batch->status = ImportBatchStatus::Processing;
            $batch->completed_at = null;
        } elseif ($problem > 0 && $imported > 0) {
            $batch->status = ImportBatchStatus::CompletedWithErrors;
            $batch->completed_at = now();
        } elseif ($problem > 0 && $imported === 0) {
            $batch->status = ImportBatchStatus::Failed;
            $batch->completed_at = now();
        } else {
            $batch->status = ImportBatchStatus::Completed;
            $batch->completed_at = now();
        }

        $batch->save();
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public function statusFromReportRow(array $row): ImportBatchItemStatus
    {
        $status = (string) ($row['status'] ?? 'error');
        $code = strtoupper((string) ($row['result_code'] ?? $status));

        return match (true) {
            $status === 'imported' => ImportBatchItemStatus::Imported,
            in_array($status, ['duplicate', 'skipped'], true) => ImportBatchItemStatus::Duplicate,
            $code === 'UNMATCHED' => ImportBatchItemStatus::Unmatched,
            $code === 'CLIENT_MISMATCH' => ImportBatchItemStatus::ClientMismatch,
            $code === 'INVALID' => ImportBatchItemStatus::Invalid,
            $code === 'UNSUPPORTED' => ImportBatchItemStatus::Unsupported,
            str_starts_with($code, 'QUARANTINE') => ImportBatchItemStatus::Quarantined,
            default => ImportBatchItemStatus::Failed,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $reportItems
     * @return array<string, mixed>
     */
    public function matchReportRow(DocumentImportBatchItem $item, array $reportItems): array
    {
        foreach ($reportItems as $row) {
            $filename = (string) ($row['filename'] ?? '');
            if ($item->entry_name !== null && $item->entry_name !== '') {
                if ($filename === $item->entry_name || str_ends_with($filename, $item->entry_name)) {
                    return $row;
                }
            }
            if ($item->sha256 && isset($row['sha256']) && hash_equals((string) $item->sha256, (string) $row['sha256'])) {
                return $row;
            }
            if ($item->access_key && isset($row['access_key']) && $row['access_key'] === $item->access_key) {
                return $row;
            }
        }

        return $reportItems[0] ?? ['status' => 'error', 'message' => 'Sem resultado correspondente no ZIP.'];
    }

    /**
     * @param  list<array{name: string, bytes: string, size: int, sha256: string, object_id: string, is_zip: bool}>  $fileMeta
     */
    private function processSynchronously(DocumentImportBatch $batch, array $fileMeta, ?int $clientId): void
    {
        $batch->status = ImportBatchStatus::Processing;
        $batch->processing_started_at = now();
        $batch->save();

        // Snapshot dos itens de topo na admissão (antes de expansões).
        $topLevel = DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $batch->id)
            ->orderBy('item_index')
            ->get()
            ->values();

        foreach ($topLevel as $idx => $item) {
            $meta = $fileMeta[$idx] ?? null;
            if ($meta === null) {
                $item->status = ImportBatchItemStatus::Failed;
                $item->result_code = 'FAILED';
                $item->result_message = 'Metadado de upload ausente.';
                $item->attempts = (int) $item->attempts + 1;
                $item->processed_at = now();
                $item->save();

                continue;
            }

            $fresh = DocumentImportBatchItem::query()->find($item->id);
            if ($fresh === null) {
                continue;
            }

            $upload = UploadedFile::fake()->createWithContent($meta['name'], $meta['bytes']);
            $report = $this->syncIngestion->ingestUploads((int) $batch->office_id, $clientId, [$upload]);
            $this->applyReportToTopLevelItem($batch, $fresh, $report['items']);
        }

        $this->recomputeBatchCounters($batch);
    }

    /**
     * Parent mantém spool do ZIP (resumo ZIP_EXPANDED); N filhos sem spool com resultados.
     *
     * @param  list<array<string, mixed>>  $reportItems
     */
    private function expandZipIntoParentAndChildren(
        DocumentImportBatch $batch,
        DocumentImportBatchItem $parent,
        array $reportItems,
    ): void {
        $startIndex = (int) $parent->item_index;
        $n = count($reportItems);

        DB::transaction(function () use ($batch, $parent, $reportItems, $startIndex, $n): void {
            // Abre N slots após o parent para os filhos (outros top-level deslocam +N).
            DocumentImportBatchItem::query()
                ->where('document_import_batch_id', $batch->id)
                ->where('item_index', '>', $startIndex)
                ->orderByDesc('item_index')
                ->get()
                ->each(function (DocumentImportBatchItem $row) use ($n): void {
                    $row->item_index = (int) $row->item_index + $n;
                    $row->save();
                });

            // Parent = resumo com spool do ZIP.
            $parent->entry_name = null;
            $parent->access_key = null;
            $parent->status = ImportBatchItemStatus::Imported;
            $parent->result_code = 'ZIP_EXPANDED';
            $parent->result_message = 'ZIP expandido em '.$n.' item(ns).';
            $parent->attempts = (int) $parent->attempts + 1;
            $parent->processed_at = now();
            $parent->save();

            foreach (array_values($reportItems) as $i => $row) {
                $entryName = $this->sanitizeEntryName((string) ($row['filename'] ?? "entry-{$i}"));
                DocumentImportBatchItem::query()->create([
                    'office_id' => $batch->office_id,
                    'document_import_batch_id' => $batch->id,
                    'item_index' => $startIndex + 1 + $i,
                    'source_name' => $parent->source_name,
                    'entry_name' => $entryName,
                    'sha256' => $row['sha256'] ?? null,
                    'access_key' => $row['access_key'] ?? null,
                    'status' => $this->statusFromReportRow($row),
                    'result_code' => $this->resultCodeFromReportRow($row),
                    'result_message' => isset($row['message'])
                        ? mb_substr((string) $row['message'], 0, 500)
                        : null,
                    'attempts' => 1,
                    'byte_size' => null,
                    'spool_vault_object_id' => null,
                    'processed_at' => now(),
                ]);
            }
        });
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function fillItemFromReportRow(DocumentImportBatchItem $item, array $row): void
    {
        $item->status = $this->statusFromReportRow($row);
        $item->access_key = $row['access_key'] ?? $item->access_key;
        if (isset($row['sha256'])) {
            $item->sha256 = $row['sha256'];
        }
        $item->result_code = $this->resultCodeFromReportRow($row);
        $item->result_message = isset($row['message'])
            ? mb_substr((string) $row['message'], 0, 500)
            : null;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function resultCodeFromReportRow(array $row): string
    {
        if (isset($row['result_code'])) {
            return strtoupper((string) $row['result_code']);
        }

        return strtoupper((string) ($row['status'] ?? 'FAILED'));
    }

    private function findZipParentItem(DocumentImportBatchItem $item): ?DocumentImportBatchItem
    {
        return DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $item->document_import_batch_id)
            ->where('office_id', $item->office_id)
            ->where('source_name', $item->source_name)
            ->whereNull('entry_name')
            ->where('result_code', 'ZIP_EXPANDED')
            ->whereNotNull('spool_vault_object_id')
            ->first();
    }

    private function resolveParentSpoolObjectId(DocumentImportBatchItem $item): ?string
    {
        return $this->findZipParentItem($item)?->spool_vault_object_id;
    }

    private function assertTenancyScope(int $officeId, ?int $clientId, ?int $establishmentId): void
    {
        if ($clientId !== null) {
            $ok = Client::query()->where('office_id', $officeId)->whereKey($clientId)->exists();
            if (! $ok) {
                throw new RuntimeException('Cliente não encontrado neste escritório.');
            }
        }

        if ($establishmentId !== null) {
            $q = Establishment::query()
                ->where('office_id', $officeId)
                ->whereKey($establishmentId);
            if ($clientId !== null) {
                $q->where('client_id', $clientId);
            }
            if (! $q->exists()) {
                throw new RuntimeException('Estabelecimento não encontrado neste escritório.');
            }
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertAdmissionLimits(array $files): void
    {
        $maxFiles = (int) config('import.max_top_level_files', 50);
        if (count($files) < 1 || count($files) > $maxFiles) {
            throw new RuntimeException("Envie entre 1 e {$maxFiles} arquivos.");
        }

        $maxTotal = (int) config('import.max_request_compressed_bytes', 20 * 1024 * 1024);
        $total = 0;
        foreach ($files as $file) {
            $total += (int) $file->getSize();
        }
        if ($total > $maxTotal) {
            throw new RuntimeException('Tamanho total dos arquivos excede o limite configurado.');
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function selectionDigest(array $files, ?int $clientId, ?int $establishmentId): string
    {
        $parts = [(string) $clientId, (string) $establishmentId];
        foreach ($files as $file) {
            $bytes = (string) file_get_contents($file->getRealPath() ?: '');
            $parts[] = hash('sha256', $bytes).':'.$file->getClientOriginalName();
        }

        return hash('sha256', implode('|', $parts));
    }

    private function sanitizeName(string $name): string
    {
        $base = basename(str_replace(["\0", '\\'], ['', '/'], $name));
        $base = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $base) ?? 'file.bin';

        return mb_substr($base, 0, 255);
    }

    /** Path interno de ZIP (preserva subdirs; remove `..` / NUL). */
    private function sanitizeEntryName(string $name): string
    {
        $name = str_replace(["\0", '\\'], ['', '/'], $name);
        $name = preg_replace('#/+#', '/', $name) ?? $name;
        $parts = array_values(array_filter(
            explode('/', ltrim($name, '/')),
            static fn (string $p): bool => $p !== '' && $p !== '.' && $p !== '..',
        ));
        $clean = [];
        foreach ($parts as $part) {
            $clean[] = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $part) ?? 'x';
        }
        $joined = implode('/', $clean);

        return mb_substr($joined !== '' ? $joined : 'entry.bin', 0, 255);
    }

    private function looksLikeZip(string $name, string $bytes): bool
    {
        return str_ends_with(strtolower($name), '.zip')
            || str_starts_with($bytes, "PK\x03\x04")
            || str_starts_with($bytes, "PK\x05\x06");
    }
}
