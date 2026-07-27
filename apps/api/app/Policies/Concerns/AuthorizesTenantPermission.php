<?php

namespace App\Policies\Concerns;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesTenantPermission
{
    protected function allows(User $user, TenantPermission $permission, mixed $target = null): bool
    {
        return app(TenantAuthorization::class)->allows($user, $permission, $target);
    }

    protected function hasTenantContext(User $user): bool
    {
        return app(CurrentTenant::class)->resolve($user) !== null;
    }

    protected function sameTenant(User $user, Model $model): bool
    {
        $tenantId = app(CurrentTenant::class)->resolve($user)?->id;
        if ($tenantId === null) {
            return false;
        }

        $modelTenantId = $model->getAttribute('tenant_id');

        return $modelTenantId !== null && (int) $modelTenantId === (int) $tenantId;
    }
}
