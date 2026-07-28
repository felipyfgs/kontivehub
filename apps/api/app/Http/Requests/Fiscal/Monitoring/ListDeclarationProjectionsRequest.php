<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\DeclarationProjectionFilters;
use App\Enums\FiscalSituation;
use App\Enums\TaxObligationApplicability;
use Illuminate\Validation\Rule;

final class ListDeclarationProjectionsRequest extends DeclarationReadRequest
{
    protected function prepareDeclarationValidation(): void
    {
        foreach (['applicability', 'situation', 'delivery_status'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => strtoupper(trim($value))]);
            }
        }

        $isOpen = $this->input('is_open');
        if (is_string($isOpen)
            && in_array(strtolower(trim($isOpen)), ['true', 'false'], true)) {
            $this->merge([
                'is_open' => strtolower(trim($isOpen)) === 'true',
            ]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'obligation_code' => ['sometimes', 'string', 'max:60'],
            'module_key' => ['sometimes', 'string', 'max:60'],
            'period_key' => ['sometimes', 'string', 'max:20'],
            'period_year' => ['sometimes', 'integer', 'between:2000,2100'],
            'period_month' => ['sometimes', 'integer', 'between:1,12'],
            'applicability' => [
                'sometimes',
                'string',
                Rule::enum(TaxObligationApplicability::class),
            ],
            'situation' => [
                'sometimes',
                'string',
                Rule::enum(FiscalSituation::class),
            ],
            'delivery_status' => [
                'sometimes',
                'string',
                Rule::enum(FiscalSituation::class),
            ],
            'is_open' => ['sometimes', 'boolean'],
            'competence_id' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function filters(): DeclarationProjectionFilters
    {
        $data = $this->validated();

        return new DeclarationProjectionFilters(
            perPage: (int) ($data['per_page'] ?? 50),
            clientId: isset($data['client_id'])
                ? (int) $data['client_id']
                : null,
            obligationCode: $data['obligation_code'] ?? null,
            moduleKey: $data['module_key'] ?? null,
            periodKey: $data['period_key'] ?? null,
            periodYear: isset($data['period_year'])
                ? (int) $data['period_year']
                : null,
            periodMonth: isset($data['period_month'])
                ? (int) $data['period_month']
                : null,
            applicability: $data['applicability'] ?? null,
            situation: $data['situation'] ?? null,
            deliveryStatus: $data['delivery_status'] ?? null,
            isOpen: array_key_exists('is_open', $data)
                ? $this->boolean('is_open')
                : null,
            competenceId: isset($data['competence_id'])
                ? (int) $data['competence_id']
                : null,
        );
    }
}
