<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\DeclarationOperationPreflightData;
use App\Enums\TenantPermission;

final class PreflightDeclarationOperationRequest extends DeclarationOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'params' => ['required', 'array'],
        ];
    }

    public function preflightData(): DeclarationOperationPreflightData
    {
        $data = $this->validated();

        return new DeclarationOperationPreflightData(
            clientId: (int) $data['client_id'],
            idempotencyKey: (string) $data['idempotency_key'],
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
        );
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
