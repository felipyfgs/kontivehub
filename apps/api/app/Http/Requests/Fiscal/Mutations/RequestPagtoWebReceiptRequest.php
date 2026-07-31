<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class RequestPagtoWebReceiptRequest extends ConfirmFiscalOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'numeroDocumento' => ['required', 'string', 'max:17'],
        ];
    }

    public function documentNumber(): string
    {
        return (string) $this->validated('numeroDocumento');
    }
}
