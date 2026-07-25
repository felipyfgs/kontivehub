<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\OperationalProcess;
use App\Models\User;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class OperationalProcessPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView);
    }

    public function view(User $user, OperationalProcess $process): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkView, $process);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkProcessesCreate);
    }

    public function update(User $user, OperationalProcess $process): bool
    {
        if ($this->allowsWork($user, TenantPermission::WorkAdminister, $process)) {
            return true;
        }

        return $this->allowsWork($user, TenantPermission::WorkProcessesCreate, $process);
    }

    public function archive(User $user, OperationalProcess $process): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkAdminister, $process);
    }

    public function bulk(User $user): bool
    {
        if ($this->allowsWork($user, TenantPermission::WorkAdminister)) {
            return true;
        }

        return $this->allowsWork($user, TenantPermission::WorkProcessesCreate);
    }

    public function comment(User $user, OperationalProcess $process): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkTasksExecute, $process);
    }
}
