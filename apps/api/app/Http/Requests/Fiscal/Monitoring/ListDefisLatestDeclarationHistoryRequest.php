<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalYearFilters;

final class ListDefisLatestDeclarationHistoryRequest extends FiscalClientReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }

    public function filters(): FiscalYearFilters
    {
        return new FiscalYearFilters(
            year: $this->validated('year') !== null
                ? (int) $this->validated('year')
                : null,
        );
    }
}
