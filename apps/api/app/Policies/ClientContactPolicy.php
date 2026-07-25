<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\ClientContact;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class ClientContactPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function view(User $user, ClientContact $contact): bool
    {
        return $this->allows($user, TenantPermission::ClientsView, $contact);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage);
    }

    public function update(User $user, ClientContact $contact): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage, $contact);
    }

    public function delete(User $user, ClientContact $contact): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage, $contact);
    }
}
