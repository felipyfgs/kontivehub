<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class CollectPgdasdDocumentRequest extends ConfirmFiscalOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'period_key' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'declaration_number' => ['sometimes', 'nullable', 'string', 'max:17'],
            'confirmed' => ['required', 'accepted'],
        ];
    }

    public function periodKey(): string
    {
        return (string) $this->validated('period_key');
    }

    public function declarationNumber(): string
    {
        return trim((string) ($this->validated('declaration_number') ?? ''));
    }
}
