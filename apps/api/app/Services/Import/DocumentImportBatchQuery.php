<?php

namespace App\Services\Import;

use App\DTO\Import\DocumentImportBatchFilters;
use App\DTO\Import\DocumentImportBatchItemFilters;
use App\Models\DocumentImportBatch;
use App\Models\DocumentImportBatchItem;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class DocumentImportBatchQuery
{
    /** @return LengthAwarePaginator<int, DocumentImportBatch> */
    public function paginateBatches(
        int $tenantId,
        DocumentImportBatchFilters $filters,
    ): LengthAwarePaginator {
        $query = DocumentImportBatch::query()
            ->where('tenant_id', $tenantId)
            ->orderBy($filters->sort, $filters->direction);

        if ($filters->sort !== 'id') {
            $query->orderBy('id', $filters->direction);
        }

        return $query->paginate($filters->perPage);
    }

    public function findBatch(int $tenantId, string $publicId): DocumentImportBatch
    {
        return DocumentImportBatch::query()
            ->where('tenant_id', $tenantId)
            ->where('public_id', $publicId)
            ->firstOrFail();
    }

    /** @return LengthAwarePaginator<int, DocumentImportBatchItem> */
    public function paginateItems(
        DocumentImportBatch $batch,
        DocumentImportBatchItemFilters $filters,
    ): LengthAwarePaginator {
        $query = DocumentImportBatchItem::query()
            ->where('tenant_id', $batch->tenant_id)
            ->where('document_import_batch_id', $batch->id)
            ->with('batch:id,public_id')
            ->when(
                $filters->status !== null,
                fn ($builder) => $builder->where('status', $filters->status),
            )
            ->orderBy($filters->sort, $filters->direction);

        if ($filters->sort !== 'id') {
            $query->orderBy('id', $filters->direction);
        }

        return $query->paginate($filters->perPage);
    }

    public function findItem(
        DocumentImportBatch $batch,
        int $itemId,
    ): DocumentImportBatchItem {
        return DocumentImportBatchItem::query()
            ->where('tenant_id', $batch->tenant_id)
            ->where('document_import_batch_id', $batch->id)
            ->with('batch')
            ->findOrFail($itemId);
    }
}
