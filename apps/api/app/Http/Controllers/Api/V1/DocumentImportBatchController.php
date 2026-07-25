<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Import\DocumentImportBatchService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentImportBatchController extends Controller
{
    public function __construct(private readonly TenantAuthorization $authorization) {}

    public function store(
        Request $request,
        CurrentOffice $currentOffice,
        DocumentImportBatchService $batches,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::DocumentsImport)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        $validated = $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:'.(int) config('import.max_top_level_files', 50)],
            'files.*' => ['file', 'max:'.(int) config('import.max_file_kib', 20480)],
            'client_id' => ['nullable', 'integer'],
            'establishment_id' => ['nullable', 'integer'],
            'idempotency_key' => ['nullable', 'string', 'max:80'],
        ]);

        $office = $currentOffice->office();
        /** @var list<UploadedFile> $files */
        $files = $request->file('files', []);
        if (! is_array($files)) {
            $files = [$files];
        }

        try {
            $result = $batches->admit(
                officeId: $office->id,
                actor: $request->user(),
                files: array_values($files),
                clientId: isset($validated['client_id']) ? (int) $validated['client_id'] : null,
                establishmentId: isset($validated['establishment_id']) ? (int) $validated['establishment_id'] : null,
                idempotencyKey: $validated['idempotency_key'] ?? $request->header('Idempotency-Key'),
            );
        } catch (RuntimeException $e) {
            $audit->record('documents.import_batch', 'FAILED', null, [
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            $audit->record('documents.import_batch', 'FAILED', null, [
                'message' => 'Falha ao admitir lote de importação.',
            ]);

            return response()->json(['message' => 'Falha ao admitir lote de importação.'], 422);
        }

        $batch = $result['batch'];
        $audit->record('documents.import_batch', $result['created'] ? 'SUCCESS' : 'IDEMPOTENT', $batch, [
            'public_id' => $batch->public_id,
            'status' => $batch->status->value,
            'file_count' => $batch->file_count,
        ]);

        // 202 quando assíncrono/queued; 200 quando já processado (transição síncrona)
        $status = $batch->status->isTerminal() ? 200 : 202;

        return response()->json([
            'data' => $batch->toPublicArray(),
        ], $status);
    }

    public function show(string $batch, CurrentOffice $currentOffice): JsonResponse
    {
        $model = $this->findBatch($batch, $currentOffice);

        return response()->json(['data' => $model->toPublicArray()]);
    }

    public function items(Request $request, string $batch, CurrentOffice $currentOffice): JsonResponse
    {
        $model = $this->findBatch($batch, $currentOffice);
        $status = $request->query('status');
        $sort = match ($request->string('sort')->toString()) {
            'status' => 'status',
            'source_name' => 'source_name',
            'id' => 'id',
            default => 'item_index',
        };
        $defaultDirection = $sort === 'item_index' ? 'asc' : 'desc';
        $requestedDirection = $request->string('direction')->lower()->toString();
        $direction = in_array($requestedDirection, ['asc', 'desc'], true)
            ? $requestedDirection
            : $defaultDirection;

        $q = DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $model->id);

        if (is_string($status) && $status !== '') {
            $q->where('status', strtoupper($status));
        }

        $q->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $q->orderBy('id', $direction);
        }

        $page = $q->paginate(min(100, max(1, (int) $request->query('per_page', 25))));

        return response()->json([
            'data' => collect($page->items())->map(fn (DocumentImportBatchItem $i) => $i->toPublicArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function retryItem(
        Request $request,
        string $batch,
        int $item,
        CurrentOffice $currentOffice,
        DocumentImportBatchService $batches,
        AuditLogger $audit,
    ): JsonResponse {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::DocumentsImport)) {
            return response()->json(['message' => 'Ação não autorizada para o perfil atual.'], 403);
        }

        $model = $this->findBatch($batch, $currentOffice);
        // office_id sempre do batch resolvido na sessão — nunca do body do cliente
        $row = DocumentImportBatchItem::query()
            ->where('document_import_batch_id', $model->id)
            ->where('office_id', $model->office_id)
            ->whereKey($item)
            ->first();
        if ($row === null) {
            abort(404);
        }

        try {
            $updated = $batches->retryItem($row);
        } catch (RuntimeException $e) {
            $audit->record('documents.import_batch.retry', 'FAILED', $model, [
                'item_id' => $row->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $audit->record('documents.import_batch.retry', 'SUCCESS', $model, [
            'item_id' => $updated->id,
            'item_index' => $updated->item_index,
            'status' => $updated->status->value,
            'result_code' => $updated->result_code,
        ]);

        return response()->json(['data' => $updated->toPublicArray()]);
    }

    public function exportCsv(string $batch, CurrentOffice $currentOffice): StreamedResponse
    {
        $model = $this->findBatch($batch, $currentOffice);
        $filename = 'import-batch-'.$model->public_id.'.csv';

        return response()->streamDownload(function () use ($model): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fputcsv($out, [
                'item_index', 'source_name', 'status', 'result_code', 'access_key',
                'model', 'issuer_cnpj', 'sha256', 'result_message',
            ]);
            DocumentImportBatchItem::query()
                ->where('document_import_batch_id', $model->id)
                ->orderBy('item_index')
                ->chunkById(200, function ($rows) use ($out): void {
                    foreach ($rows as $item) {
                        /** @var DocumentImportBatchItem $item */
                        fputcsv($out, [
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
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $office = $currentOffice->office();
        $sort = match ($request->string('sort')->toString()) {
            'status' => 'status',
            'created_at' => 'created_at',
            'file_count' => 'file_count',
            'imported_count' => 'imported_count',
            default => 'id',
        };
        $direction = $request->string('direction')->lower()->toString() === 'asc' ? 'asc' : 'desc';
        $query = DocumentImportBatch::query()
            ->where('office_id', $office->id)
            ->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
        $page = $query->paginate(min(50, max(1, (int) $request->query('per_page', 20))));

        return response()->json([
            'data' => collect($page->items())->map(fn (DocumentImportBatch $b) => $b->toPublicArray())->values(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    private function findBatch(string $publicId, CurrentOffice $currentOffice): DocumentImportBatch
    {
        $office = $currentOffice->office();
        $batch = DocumentImportBatch::query()
            ->where('office_id', $office->id)
            ->where('public_id', $publicId)
            ->first();

        if ($batch === null) {
            abort(404);
        }

        return $batch;
    }
}
