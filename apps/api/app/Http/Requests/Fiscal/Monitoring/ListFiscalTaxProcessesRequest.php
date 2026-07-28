<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalRecordFilters;

final class ListFiscalTaxProcessesRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'max:40'],
            'q' => ['sometimes', 'string', 'max:120'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): FiscalRecordFilters
    {
        $validated = $this->validated();
        $search = trim((string) ($validated['q'] ?? ''));

        return new FiscalRecordFilters(
            perPage: (int) ($validated['per_page'] ?? 25),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            status: isset($validated['status'])
                ? (string) $validated['status']
                : null,
            search: $search !== '' ? $search : null,
        );
    }
}
