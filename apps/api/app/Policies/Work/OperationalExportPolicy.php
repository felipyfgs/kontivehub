<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\OperationalExport;
use App\Models\User;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class OperationalExportPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate);
    }

    public function view(User $user, OperationalExport $export): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate, $export);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate);
    }

    public function download(User $user, OperationalExport $export): bool
    {
        return $this->view($user, $export);
    }
}
