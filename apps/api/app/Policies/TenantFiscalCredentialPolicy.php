<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\TenantCredential;
use App\Models\TenantFiscalIdentity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

/**
 * Metadados de credencial fiscal: leitura ampla no tenant; mutação = credentials.manage.
 */
class TenantFiscalCredentialPolicy
{
    use AuthorizesTenantPermission;

    public function view(User $user): bool
    {
        return $this->allows($user, TenantPermission::CredentialsStatusView);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage);
    }

    public function viewIdentity(User $user, TenantFiscalIdentity $identity): bool
    {
        return $this->allows($user, TenantPermission::CredentialsStatusView, $identity);
    }

    public function manageIdentity(User $user, TenantFiscalIdentity $identity): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $identity);
    }

    public function viewCredential(User $user, TenantCredential $credential): bool
    {
        return $this->allows($user, TenantPermission::CredentialsStatusView, $credential);
    }

    public function manageCredential(User $user, TenantCredential $credential): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $credential);
    }
}
