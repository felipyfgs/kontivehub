<?php

namespace App\Policies\Work;

use App\Enums\TenantPermission;
use App\Models\User;
use App\Models\WorkExport;
use App\Policies\Work\Concerns\UsesRealWorkRole;

class WorkExportPolicy
{
    use UsesRealWorkRole;

    public function viewAny(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate);
    }

    public function view(User $user, WorkExport $export): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate, $export);
    }

    public function create(User $user): bool
    {
        return $this->allowsWork($user, TenantPermission::WorkExportsCreate);
    }

    public function download(User $user, WorkExport $export): bool
    {
        return $this->view($user, $export);
    }
}
