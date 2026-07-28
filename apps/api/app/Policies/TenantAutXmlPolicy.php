<?php

namespace App\Policies;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Policies\Concerns\AuthorizesTenantPermission;

final class TenantAutXmlPolicy
{
    use AuthorizesTenantPermission;

    public function view(User $user): bool
    {
        return $this->allows($user, TenantPermission::FiscalMonitoringView);
    }

    public function manage(User $user): bool
    {
        return $this->allows($user, TenantPermission::ClientsManage);
    }
}
