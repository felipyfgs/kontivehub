<?php

namespace App\Http\Requests\Fiscal\Mutations;

use App\Http\Requests\AuthenticatedRequest;

/**
 * Confirmação explícita para mutações de monitoramento (consult/issue).
 * Rejeição de tenant_id permanece no controller quando o envelope de erro
 * inclui code CLIENT_TENANT_ID_REJECTED.
 */
class ConfirmFiscalOperationRequest extends AuthenticatedRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'confirmed' => ['required', 'accepted'],
        ];
    }
}
