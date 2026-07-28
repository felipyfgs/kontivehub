<?php

namespace App\Actions\Import;

use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ExportDocumentImportBatchCsvAction
{
    public function execute(DocumentImportBatch $batch): StreamedResponse
    {
        $filename = 'import-batch-'.$batch->public_id.'.csv';

        return response()->streamDownload(function () use ($batch): void {
            $output = fopen('php://output', 'w');
            if ($output === false) {
                return;
            }

            fputcsv($output, [
                'item_index',
                'source_name',
                'status',
                'result_code',
                'access_key',
                'model',
                'issuer_cnpj',
                'sha256',
                'result_message',
            ]);

            DocumentImportBatchItem::query()
                ->where('tenant_id', $batch->tenant_id)
                ->where('document_import_batch_id', $batch->id)
                ->orderBy('item_index')
                ->chunkById(200, function ($rows) use ($output): void {
                    foreach ($rows as $item) {
                        /** @var DocumentImportBatchItem $item */
                        fputcsv($output, [
                            $item->item_index,
                            $item->source_name,
                            $item->status->value,
                            $item->result_code,
                            $item->access_key,
                            $item->model,
                            $item->issuer_cnpj,
                            $item->sha256,
                            $item->result_message,
                        ]);
                    }
                });

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
