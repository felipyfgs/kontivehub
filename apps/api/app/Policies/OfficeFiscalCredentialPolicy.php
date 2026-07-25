<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\OfficeCredential;
use App\Models\OfficeFiscalIdentity;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

/**
 * Metadados de credencial fiscal: leitura ampla no office; mutação = credentials.manage.
 */
class OfficeFiscalCredentialPolicy
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

    public function viewIdentity(User $user, OfficeFiscalIdentity $identity): bool
    {
        return $this->allows($user, TenantPermission::CredentialsStatusView, $identity);
    }

    public function manageIdentity(User $user, OfficeFiscalIdentity $identity): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $identity);
    }

    public function viewCredential(User $user, OfficeCredential $credential): bool
    {
        return $this->allows($user, TenantPermission::CredentialsStatusView, $credential);
    }

    public function manageCredential(User $user, OfficeCredential $credential): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $credential);
    }
}
