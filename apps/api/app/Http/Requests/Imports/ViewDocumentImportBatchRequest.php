<?php

namespace App\Http\Requests\Imports;

final class ViewDocumentImportBatchRequest extends DocumentImportBatchReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
        ];
    }
}
