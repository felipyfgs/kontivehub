<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class ConsultDefisSpecificRequest extends ConfirmFiscalOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
            'reference_id' => ['required', 'integer', 'min:1'],
        ];
    }

    public function referenceId(): int
    {
        return (int) $this->validated('reference_id');
    }
}
