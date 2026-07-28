<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\TaxGuidePreflightData;

final class PreflightTaxGuideRequest extends TaxGuideWriteRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'operation_key' => [
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)+$/',
            ],
            'competence_period_key' => ['sometimes', 'nullable', 'string', 'max:20'],
            'debit_ref' => ['sometimes', 'nullable', 'string', 'max:120'],
            'amount_cents' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'tenant_id' => ['prohibited'],
        ];
    }

    public function preflightData(): TaxGuidePreflightData
    {
        $data = $this->validated();

        return new TaxGuidePreflightData(
            clientId: (int) $data['client_id'],
            operationKey: (string) $data['operation_key'],
            competencePeriodKey: isset($data['competence_period_key'])
                ? (string) $data['competence_period_key']
                : null,
            debitRef: isset($data['debit_ref'])
                ? (string) $data['debit_ref']
                : null,
            amountCents: isset($data['amount_cents'])
                ? (int) $data['amount_cents']
                : null,
        );
    }
}
