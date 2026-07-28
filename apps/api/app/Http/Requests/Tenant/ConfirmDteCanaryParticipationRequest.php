<?php

namespace App\Http\Requests\Tenant;

use App\Exceptions\DteCanaryApiException;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;

final class ConfirmDteCanaryParticipationRequest extends AuthenticatedRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw DteCanaryApiException::clientTenantIdRejected(
                'tenant_id do client não é aceito; use o Tenant corrente.',
            );
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [];
    }
}
