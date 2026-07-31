<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalPageFilters;

final class ListPagtoWebPaymentHistoryRequest extends FiscalClientReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes'],
            'per_page' => ['sometimes'],
        ];
    }

    public function filters(): FiscalPageFilters
    {
        $data = $this->validated();

        return new FiscalPageFilters(
            page: max(1, (int) ($data['page'] ?? 1)),
            perPage: min(100, max(1, (int) ($data['per_page'] ?? 50))),
        );
    }
}
