<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

final class TenantMemberPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantUsersView);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantUsersCreate);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantUsersManage);
    }

    public function assignAdmin(User $user): bool
    {
        return $this->allows($user, TenantPermission::TenantRolesAssignAdmin);
    }
}
