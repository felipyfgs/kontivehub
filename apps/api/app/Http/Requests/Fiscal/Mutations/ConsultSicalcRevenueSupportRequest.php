<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class ConsultSicalcRevenueSupportRequest extends ConfirmFiscalOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'codigo_receita' => ['required', 'string', 'regex:/^[0-9]{1,16}$/'],
        ];
    }

    public function revenueCode(): string
    {
        return (string) $this->validated('codigo_receita');
    }
}
