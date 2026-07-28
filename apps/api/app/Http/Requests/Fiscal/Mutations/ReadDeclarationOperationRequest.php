<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\DTO\Fiscal\Mutations\DeclarationOperationReadData;
use App\Enums\TenantPermission;

final class ReadDeclarationOperationRequest extends DeclarationOperationRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
            'params' => ['sometimes', 'array'],
        ];
    }

    public function readData(): DeclarationOperationReadData
    {
        $data = $this->validated();

        return new DeclarationOperationReadData(
            clientId: (int) $data['client_id'],
            params: is_array($data['params'] ?? null) ? $data['params'] : [],
            confirmed: true,
        );
    }

    protected function permission(): TenantPermission
    {
        return TenantPermission::FiscalSyncTrigger;
    }
}
