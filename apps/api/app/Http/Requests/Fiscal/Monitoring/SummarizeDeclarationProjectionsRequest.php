<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\DeclarationSummaryFilters;

final class SummarizeDeclarationProjectionsRequest extends DeclarationReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'period_key' => ['sometimes', 'string', 'max:20'],
        ];
    }

    public function filters(): DeclarationSummaryFilters
    {
        $data = $this->validated();

        return new DeclarationSummaryFilters(
            clientId: isset($data['client_id'])
                ? (int) $data['client_id']
                : null,
            periodKey: $data['period_key'] ?? null,
        );
    }
}
