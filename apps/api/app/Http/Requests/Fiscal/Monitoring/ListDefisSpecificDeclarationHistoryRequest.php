<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\DefisSpecificDeclarationFilters;

final class ListDefisSpecificDeclarationHistoryRequest extends FiscalClientReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'reference_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): DefisSpecificDeclarationFilters
    {
        return new DefisSpecificDeclarationFilters(
            referenceId: $this->validated('reference_id') !== null
                ? (int) $this->validated('reference_id')
                : null,
        );
    }
}
