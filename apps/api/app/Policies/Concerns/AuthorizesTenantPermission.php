<?php

namespace App\Policies\Concerns;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Support\CurrentOffice;
use Illuminate\Database\Eloquent\Model;

trait AuthorizesTenantPermission
{
    protected function allows(User $user, TenantPermission $permission, mixed $target = null): bool
    {
        return app(TenantAuthorization::class)->allows($user, $permission, $target);
    }

    protected function hasOfficeContext(User $user): bool
    {
        return app(CurrentOffice::class)->resolve($user) !== null;
    }

    protected function sameOffice(User $user, Model $model): bool
    {
        $officeId = app(CurrentOffice::class)->resolve($user)?->id;
        if ($officeId === null) {
            return false;
        }

        $modelOfficeId = $model->getAttribute('office_id');

        return $modelOfficeId !== null && (int) $modelOfficeId === (int) $officeId;
    }
}
