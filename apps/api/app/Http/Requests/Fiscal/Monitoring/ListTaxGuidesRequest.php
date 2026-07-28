<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\TaxGuideFilters;
use App\Enums\TaxGuidePaymentStatus;
use Illuminate\Validation\Rule;

final class ListTaxGuidesRequest extends TaxGuideReadRequest
{
    protected function prepareTaxGuideValidation(): void
    {
        foreach (['payment_status', 'sort_direction'] as $key) {
            $value = $this->input($key);
            if (is_string($value)) {
                $this->merge([$key => strtoupper(trim($value))]);
            }
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'client_id' => ['sometimes', 'integer', 'min:1'],
            'payment_status' => [
                'sometimes',
                'string',
                Rule::enum(TaxGuidePaymentStatus::class),
            ],
            'sort' => [
                'sometimes',
                'string',
                Rule::in([
                    'client_id',
                    'system_code',
                    'competence',
                    'amount',
                    'due_at',
                    'payment_status',
                ]),
            ],
            'sort_direction' => [
                'sometimes',
                'string',
                Rule::in(['ASC', 'DESC']),
            ],
        ];
    }

    public function filters(): TaxGuideFilters
    {
        $data = $this->validated();

        return new TaxGuideFilters(
            page: (int) ($data['page'] ?? 1),
            perPage: (int) ($data['per_page'] ?? 50),
            clientId: isset($data['client_id'])
                ? (int) $data['client_id']
                : null,
            paymentStatus: $data['payment_status'] ?? null,
            sort: $data['sort'] ?? '',
            sortDirection: isset($data['sort_direction'])
                ? strtolower((string) $data['sort_direction'])
                : '',
        );
    }
}
