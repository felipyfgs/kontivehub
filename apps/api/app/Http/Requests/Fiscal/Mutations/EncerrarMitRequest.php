<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

final class EncerrarMitRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'period_key' => ['required', 'string', 'max:20'],
            'correlation_id' => ['sometimes', 'string', 'max:64'],
            'confirmation' => ['required', 'accepted'],
            'tenant_id' => ['prohibited'],
        ];
    }

    /** @return array<string, mixed> */
    public function encerrarData(): array
    {
        return $this->validated();
    }
}
