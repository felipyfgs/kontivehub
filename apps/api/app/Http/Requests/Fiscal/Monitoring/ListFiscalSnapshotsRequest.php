<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalSnapshotFilters;

final class ListFiscalSnapshotsRequest extends FiscalMonitoringViewRequest
{
    protected function prepareFiscalMonitoringValidation(): void
    {
        $this->normalizeBooleanInput('current_only');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'current_only' => ['sometimes', 'boolean'],
        ];
    }

    public function filters(): FiscalSnapshotFilters
    {
        $validated = $this->validated();

        return new FiscalSnapshotFilters(
            perPage: (int) ($validated['per_page'] ?? 50),
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            currentOnly: array_key_exists('current_only', $validated)
                ? $this->boolean('current_only')
                : true,
        );
    }
}
