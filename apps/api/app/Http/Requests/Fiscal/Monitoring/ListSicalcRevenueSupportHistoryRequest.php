<?php

namespace App\Http\Requests\Fiscal\Monitoring;

use App\DTO\Fiscal\Monitoring\SicalcRevenueSupportFilters;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

final class ListSicalcRevenueSupportHistoryRequest extends FiscalClientReadRequest
{
    protected function prepareFiscalClientValidation(): void
    {
        $code = $this->input('codigo_receita');
        if (is_string($code)) {
            $this->merge(['codigo_receita' => trim($code)]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'codigo_receita' => [
                'sometimes',
                'string',
                'regex:/^[0-9]{1,16}$/',
            ],
        ];
    }

    public function filters(): SicalcRevenueSupportFilters
    {
        return new SicalcRevenueSupportFilters(
            revenueCode: $this->validated('codigo_receita'),
        );
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => 'codigo_receita deve conter apenas de 1 a 16 algarismos.',
            'code' => 'INVALID_REVENUE_CODE',
            'errors' => $validator->errors(),
        ], 422));
    }
}
