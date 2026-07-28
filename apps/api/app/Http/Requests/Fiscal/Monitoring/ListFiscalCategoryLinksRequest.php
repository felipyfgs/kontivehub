<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\FiscalCategoryLinkFilters;
use App\Enums\FiscalLinkStatus;
use Illuminate\Validation\Rule;

final class ListFiscalCategoryLinksRequest extends ViewFiscalMonitoringSurfaceRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', Rule::enum(FiscalLinkStatus::class)],
        ];
    }

    public function filters(): FiscalCategoryLinkFilters
    {
        $validated = $this->validated();

        return new FiscalCategoryLinkFilters(
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            status: isset($validated['status'])
                ? (string) $validated['status']
                : null,
        );
    }
}
