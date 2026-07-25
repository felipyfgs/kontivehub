<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\DocumentImportBatchItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Import\DocumentImportBatchService;
use App\Services\Import\OutboundXmlIngestionService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Throwable;

/**
 * Alias de transição: mantém resposta síncrona legada quando
 * IMPORT_ASYNC_BATCHES_ENABLED=false; com flag on, delega ao batch 202.
 */
class DocumentImportController extends Controller
{
    public function __construct(private readonly TenantAuthorization $authorization) {}

    public function store(
        Request $request,
        CurrentOffice $currentOffice,
        OutboundXmlIngestionService $ingestion,
        DocumentImportBatchService $batches,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::DocumentsImport)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        if (config('import.async_batches_enabled')) {
            return app(DocumentImportBatchController::class)
                ->store($request, $currentOffice, $batches, $audit);
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('import.max_top_level_files', 50)],
            'files.*' => ['file', 'max:'.(int) config('import.max_file_kib', 20480)],
            'client_id' => ['nullable', 'integer'],
        ]);

        $office = $currentOffice->office();
        $clientId = isset($validated['client_id']) ? (int) $validated['client_id'] : null;

        if ($clientId !== null) {
            $exists = Client::query()
                ->where('office_id', $office->id)
                ->where('id', $clientId)
                ->exists();
            if (! $exists) {
                return response()->json(['message' => 'Cliente não encontrado neste escritório.'], 422);
            }
        }

        /** @var list<UploadedFile> $files */
        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = [$files];
        }

        // Também registra batch para histórico, processando de forma síncrona.
        try {
            $result = $batches->admit(
                officeId: $office->id,
                actor: $request->user(),
                files: array_values($files),
                clientId: $clientId,
                establishmentId: null,
                idempotencyKey: $request->header('Idempotency-Key'),
            );
            $batch = $result['batch'];
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            // Fallback puro ao caminho legado se batch falhar
            $report = $ingestion->ingestUploads($office->id, $clientId, array_values($files));
            $audit->record('documents.import', 'SUCCESS', null, [
                'imported' => $report['imported'],
                'skipped' => $report['skipped'],
                'errors' => $report['errors'],
                'client_id' => $clientId,
                'batch_fallback' => true,
            ]);

            return response()->json([
                'data' => $report,
            ], $report['imported'] > 0 || $report['skipped'] > 0 ? 200 : 422);
        }

        $report = [
            'imported' => $batch->imported_count,
            'skipped' => $batch->duplicate_count,
            'errors' => $batch->failed_count + $batch->invalid_count + $batch->unmatched_count,
            'items' => DocumentImportBatchItem::query()
                ->where('document_import_batch_id', $batch->id)
                ->orderBy('item_index')
                ->get()
                ->map(fn ($i) => [
                    'status' => strtolower($i->status->value === 'IMPORTED' ? 'imported' : ($i->status->value === 'DUPLICATE' ? 'duplicate' : 'error')),
                    'filename' => $i->source_name,
                    'access_key' => $i->access_key,
                    'message' => $i->result_message,
                    'sha256' => $i->sha256,
                ])
                ->all(),
            'batch_id' => $batch->public_id,
        ];

        $audit->record('documents.import', 'SUCCESS', $batch, [
            'imported' => $report['imported'],
            'skipped' => $report['skipped'],
            'errors' => $report['errors'],
            'client_id' => $clientId,
            'batch_id' => $batch->public_id,
        ]);

        return response()->json([
            'data' => $report,
        ], $report['imported'] > 0 || $report['skipped'] > 0 ? 200 : 422);
    }
}
