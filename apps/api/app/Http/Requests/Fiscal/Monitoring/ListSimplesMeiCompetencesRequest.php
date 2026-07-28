<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SimplesMeiCompetenceFilters;
use Illuminate\Validation\Rule;

final class ListSimplesMeiCompetencesRequest extends ViewSimplesMeiClientRequest
{
    protected function prepareSimplesMeiValidation(): void
    {
        $family = $this->input('regime_family');
        if (is_string($family)) {
            $this->merge(['regime_family' => strtoupper(trim($family))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'regime_family' => [
                'sometimes',
                'string',
                Rule::in(['SIMPLES_NACIONAL', 'MEI']),
            ],
        ];
    }

    public function filters(): SimplesMeiCompetenceFilters
    {
        $data = $this->validated();

        return new SimplesMeiCompetenceFilters(
            regimeFamily: $data['regime_family'] ?? null,
        );
    }
}
