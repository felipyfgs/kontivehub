<?php

namespace App\Http\Requests\FgtsEsocial;

use App\Enums\TenantPermission;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use Illuminate\Validation\ValidationException;

abstract class FgtsEsocialRequest extends AuthenticatedRequest
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

        $this->prepareFgtsEsocialValidation();
    }

    protected function prepareFgtsEsocialValidation(): void {}

    final protected function canView(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalMonitoringView,
            );
    }

    final protected function canOperate(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(TenantAuthorization::class)->allows(
                $actor,
                TenantPermission::FiscalSyncTrigger,
            );
    }
}
