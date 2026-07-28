<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalMonitoringRunFilters;
use App\Enums\FiscalRunStatus;
use Illuminate\Validation\Rule;

final class ListFiscalMonitoringRunsRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(FiscalRunStatus::class)],
        ];
    }

    public function filters(): FiscalMonitoringRunFilters
    {
        $validated = $this->validated();

        return new FiscalMonitoringRunFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            status: isset($validated['status'])
                ? (string) $validated['status']
                : null,
        );
    }
}
