<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Import\AdmitDocumentImportBatchAction;
use App\Actions\Import\ExportDocumentImportBatchCsvAction;
use App\Actions\Import\RetryDocumentImportBatchItemAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Imports\ListDocumentImportBatchesRequest;
use App\Http\Requests\Imports\ListDocumentImportBatchItemsRequest;
use App\Http\Requests\Imports\RetryDocumentImportBatchItemRequest;
use App\Http\Requests\Imports\StoreDocumentImportBatchRequest;
use App\Http\Requests\Imports\ViewDocumentImportBatchRequest;
use App\Http\Resources\Import\DocumentImportBatchItemPageResource;
use App\Http\Resources\Import\DocumentImportBatchItemResource;
use App\Http\Resources\Import\DocumentImportBatchPageResource;
use App\Http\Resources\Import\DocumentImportBatchResource;
use App\Services\Import\DocumentImportBatchQuery;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DocumentImportBatchController extends Controller
{
    public function store(
        StoreDocumentImportBatchRequest $request,
        CurrentTenant $currentTenant,
        AdmitDocumentImportBatchAction $action,
    ): JsonResponse {
        $result = $action->execute(
            tenantId: $currentTenant->tenant()->id,
            data: $request->admissionData(),
        );
        $status = $result->batch->status->isTerminal() ? 200 : 202;

        return (new DocumentImportBatchResource(
            $result->batch,
        ))->response()->setStatusCode($status);
    }

    public function show(
        ViewDocumentImportBatchRequest $request,
        string $batch,
        CurrentTenant $currentTenant,
        DocumentImportBatchQuery $query,
    ): JsonResponse {
        $model = $query->findBatch(
            $currentTenant->tenant()->id,
            $batch,
        );

        return (new DocumentImportBatchResource($model))->response();
    }

    public function items(
        ListDocumentImportBatchItemsRequest $request,
        string $batch,
        CurrentTenant $currentTenant,
        DocumentImportBatchQuery $query,
    ): JsonResponse {
        $model = $query->findBatch(
            $currentTenant->tenant()->id,
            $batch,
        );

        return (new DocumentImportBatchItemPageResource(
            $query->paginateItems($model, $request->filters()),
        ))->response();
    }

    public function retryItem(
        RetryDocumentImportBatchItemRequest $request,
        string $batch,
        int $item,
        CurrentTenant $currentTenant,
        DocumentImportBatchQuery $query,
        RetryDocumentImportBatchItemAction $action,
    ): JsonResponse {
        $model = $query->findBatch(
            $currentTenant->tenant()->id,
            $batch,
        );

        return (new DocumentImportBatchItemResource(
            $action->execute($model, $item),
        ))->response();
    }

    public function exportCsv(
        ViewDocumentImportBatchRequest $request,
        string $batch,
        CurrentTenant $currentTenant,
        DocumentImportBatchQuery $query,
        ExportDocumentImportBatchCsvAction $action,
    ): StreamedResponse {
        return $action->execute($query->findBatch(
            $currentTenant->tenant()->id,
            $batch,
        ));
    }

    public function index(
        ListDocumentImportBatchesRequest $request,
        CurrentTenant $currentTenant,
        DocumentImportBatchQuery $query,
    ): JsonResponse {
        return (new DocumentImportBatchPageResource(
            $query->paginateBatches(
                $currentTenant->tenant()->id,
                $request->filters(),
            ),
        ))->response();
    }
}
