<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class WorkDepartmentPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, WorkDepartment $department): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $department);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage);
    }

    public function update(User $user, WorkDepartment $department): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkCatalogManage, $department);
    }

    public function delete(User $user, WorkDepartment $department): bool
    {
        return $this->update($user, $department);
    }
}
