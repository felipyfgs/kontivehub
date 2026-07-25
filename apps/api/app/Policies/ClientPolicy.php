<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Client;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class ClientPolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsView);
    }

    public function view(User $user, Client $client): bool
    {
        return $this->allows($user, TenantPermission::ClientsView, $client);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage);
    }

    public function update(User $user, Client $client): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage, $client);
    }

    public function delete(User $user, Client $client): bool
    {
        // Delete permanece baseline admin / credentials-level (legado: Admin only).
        return $this->allows($user, TenantPermission::CredentialsManage, $client);
    }
}
