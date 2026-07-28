<?php

namespace App\Http\Requests\Imports;

use App\DTO\Import\DocumentImportBatchFilters;

final class ListDocumentImportBatchesRequest extends DocumentImportBatchReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'sort' => ['nullable', 'string'],
            'direction' => ['nullable', 'string'],
            'per_page' => ['nullable'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function filters(): DocumentImportBatchFilters
    {
        $sort = (string) ($this->validated('sort') ?? '');
        $direction = strtolower((string) ($this->validated('direction') ?? ''));

        return new DocumentImportBatchFilters(
            sort: in_array($sort, [
                'status',
                'created_at',
                'file_count',
                'imported_count',
                'id',
            ], true) ? $sort : 'id',
            direction: $direction === 'asc' ? 'asc' : 'desc',
            perPage: min(50, max(1, (int) ($this->validated('per_page') ?? 20))),
        );
    }
}
