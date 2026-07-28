<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\TaxInstallmentListFilters;

final class ListTaxInstallmentGuidesRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function filters(): TaxInstallmentListFilters
    {
        $validated = $this->validated();

        return new TaxInstallmentListFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
        );
    }
}
