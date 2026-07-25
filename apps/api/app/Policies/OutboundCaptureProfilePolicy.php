<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\OutboundCaptureProfile;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

class OutboundCaptureProfilePolicy
{
    use AuthorizesTenantPermission;

    public function viewAny(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiscalDocumentsView);
    }

    public function view(User $user, OutboundCaptureProfile $profile): bool
    {
        return $this->allows($user, TenantPermission::FiscalDocumentsView, $profile);
    }

    public function create(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage);
    }

    public function update(User $user, OutboundCaptureProfile $profile): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage, $profile);
    }

    /** Ativação, allowlist, mandato, kill switch, CSC. */
    public function administer(User $user, OutboundCaptureProfile $profile): bool
    {
        return $this->allows($user, TenantPermission::CredentialsManage, $profile);
    }

    public function triggerReadOnly(User $user, OutboundCaptureProfile $profile): bool
    {
        return $this->allows($user, TenantPermission::FiscalSyncTrigger, $profile);
    }

    public function uploadPackage(User $user, OutboundCaptureProfile $profile): bool
    {
        return $this->triggerReadOnly($user, $profile);
    }
}
