<?php

namespace App\Http\Requests\Outbound;

use App\Enums\TenantRole;
use App\Http\Middleware\EnsureTenantContext;
use App\Http\Requests\AuthenticatedRequest;
use App\Models\User;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Support\CurrentTenant;
use Illuminate\Validation\ValidationException;

abstract class OutboundRequest extends AuthenticatedRequest
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

        $this->prepareOutboundValidation();
    }

    protected function prepareOutboundValidation(): void {}

    final protected function canView(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() !== null;
    }

    final protected function canOperate(): bool
    {
        return $this->user() instanceof User
            && in_array(app(CurrentTenant::class)->role(), [
                TenantRole::TenantAdmin,
                TenantRole::TenantUser,
            ], true);
    }

    final protected function canAdminister(): bool
    {
        return $this->user() instanceof User
            && app(CurrentTenant::class)->role() === TenantRole::TenantAdmin;
    }

    final protected function canAccessSecret(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && $this->canAdminister()
            && app(RecentPasswordConfirmationGate::class)->isRecentlyConfirmed(
                $actor,
                $this,
            );
    }
}
