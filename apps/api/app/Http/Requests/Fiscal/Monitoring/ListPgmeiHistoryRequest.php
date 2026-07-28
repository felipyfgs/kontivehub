<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SimplesMeiHistoryFilters;

final class ListPgmeiHistoryRequest extends SimplesMeiModuleReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'year' => ['sometimes', 'integer', 'between:2000,2100'],
        ];
    }

    public function filters(): SimplesMeiHistoryFilters
    {
        $year = $this->validated('year');

        return new SimplesMeiHistoryFilters(
            year: $year !== null ? (int) $year : null,
        );
    }
}
