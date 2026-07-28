<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalPendingItemFilters;
use App\Enums\FiscalPendingStatus;
use Illuminate\Validation\Rule;

final class ListFiscalPendingItemsRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(FiscalPendingStatus::class)],
        ];
    }

    public function filters(): FiscalPendingItemFilters
    {
        $validated = $this->validated();

        return new FiscalPendingItemFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            status: (string) ($validated['status'] ?? FiscalPendingStatus::Open->value),
        );
    }
}
