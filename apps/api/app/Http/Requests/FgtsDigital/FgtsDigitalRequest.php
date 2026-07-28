<?php

namespace App\Http\Requests\FgtsDigital;

use App\Enums\TenantPermission;
use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;

abstract class FgtsDigitalRequest extends AuthenticatedRequest
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

        $this->prepareFgtsDigitalValidation();
    }

    protected function prepareFgtsDigitalValidation(): void {}

    final protected function canView(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
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

    final protected function canAdminister(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() === TenantRole::TenantAdmin;
    }
}
