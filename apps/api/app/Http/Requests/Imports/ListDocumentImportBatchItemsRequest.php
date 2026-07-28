<?php

namespace App\Http\Requests\Imports;

use App\DTO\Import\DocumentImportBatchItemFilters;

final class ListDocumentImportBatchItemsRequest extends DocumentImportBatchReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['nullable', 'string'],
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'per_page' => ['nullable'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function filters(): DocumentImportBatchItemFilters
    {
        $status = $this->validated('status');
        $requestedSort = (string) ($this->validated('sort') ?? '');
        $sort = in_array($requestedSort, [
            'status',
            'source_name',
            'id',
            'item_index',
        ], true) ? $requestedSort : 'item_index';
        $defaultDirection = $sort === 'item_index' ? 'asc' : 'desc';
        $requestedDirection = strtolower(
            (string) ($this->validated('direction') ?? ''),
        );

        return new DocumentImportBatchItemFilters(
            status: is_string($status) && $status !== ''
                ? strtoupper($status)
                : null,
            sort: $sort,
            direction: in_array($requestedDirection, ['asc', 'desc'], true)
                ? $requestedDirection
                : $defaultDirection,
            perPage: min(100, max(1, (int) ($this->validated('per_page') ?? 25))),
        );
    }
}
