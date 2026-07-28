<?php

namespace App\Http\Requests\Communication;

use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use Illuminate\Validation\ValidationException;

abstract class CommunicationRequest extends AuthenticatedRequest
{
    final protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)
            || $this->request->has('tenant_id')
            || $this->query->has('tenant_id')) {
            throw ValidationException::withMessages([
                'tenant_id' => [
                    'O escopo do escritório é derivado da sessão; tenant_id não é aceito.',
                ],
            ]);
        }

        $this->prepareCommunicationValidation();
    }

    protected function prepareCommunicationValidation(): void {}
}
