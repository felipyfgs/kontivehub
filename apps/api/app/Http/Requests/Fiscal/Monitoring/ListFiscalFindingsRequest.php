<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalFindingFilters;

final class ListFiscalFindingsRequest extends FiscalMonitoringViewRequest
{
    protected function prepareFiscalMonitoringValidation(): void
    {
        $this->normalizeBooleanInput('active_only');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'active_only' => ['sometimes', 'boolean'],
        ];
    }

    public function filters(): FiscalFindingFilters
    {
        $validated = $this->validated();

        return new FiscalFindingFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            activeOnly: array_key_exists('active_only', $validated)
                ? $this->boolean('active_only')
                : true,
        );
    }
}
