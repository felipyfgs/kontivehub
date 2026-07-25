<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Establishment;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class EstablishmentPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function view(User $user, Establishment $establishment): bool
    {
        return $this->allows($user, TenantPermission::ClientsView, $establishment);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage);
    }

    public function update(User $user, Establishment $establishment): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage, $establishment);
    }

    public function delete(User $user, Establishment $establishment): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $establishment);
    }
}
