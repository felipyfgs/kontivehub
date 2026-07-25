<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\Client;
use App\Models\ClientCredential;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class ClientCredentialPolicy
{
    use AuthorizesTenantPermission;

    public function view(User $user, Client $client): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $client);
    }

    public function manage(User $user, Client $client): bool
    {
        return $this->view($user, $client);
    }

    public function viewCredential(User $user, ClientCredential $credential): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $credential);
    }
}
