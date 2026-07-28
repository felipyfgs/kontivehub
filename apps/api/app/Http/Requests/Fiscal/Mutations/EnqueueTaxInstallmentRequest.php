<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class EnqueueTaxInstallmentRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'modality' => ['required', 'string', 'max:20'],
            'operation_code' => ['sometimes', 'string', 'max:80'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'context' => ['sometimes', 'array'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function enqueueData(): array
    {
        return $this->validated();
    }
}
