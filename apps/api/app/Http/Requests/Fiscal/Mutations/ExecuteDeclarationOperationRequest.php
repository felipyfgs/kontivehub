<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\DeclarationOperationExecuteData;
use App\Enums\TenantPermission;

final class ExecuteDeclarationOperationRequest extends DeclarationOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'preflight_token' => ['required', 'string', 'max:64'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
            'params' => ['required', 'array'],
        ];
    }

    public function executeData(): DeclarationOperationExecuteData
    {
        $data = $this->validated();

        return new DeclarationOperationExecuteData(
            clientId: (int) $data['client_id'],
            idempotencyKey: (string) $data['idempotency_key'],
            preflightToken: (string) $data['preflight_token'],
            confirmationPhrase: (string) $data['confirmation_phrase'],
            confirmed: (bool) $data['confirmed'],
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
        );
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalMutationsExecute;
    }
}
